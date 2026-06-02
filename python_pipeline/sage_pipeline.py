"""SAGE knowledge-graph pipeline.

Implements the three-pass offline build and incremental update pipeline for
the Structure Aware Graph Expansion (SAGE) retrieval system.

Passes
------
Pass 1   : Entity extraction — spaCy NER over all corpus chunks. Entities are
           stored as ``"TYPE:text"`` strings (e.g. ``"PERSON:Martin Luther King"``)
           so that edge classification in Pass 3 does not require re-running spaCy.
Pass 2   : Embedding + insert — each chunk is embedded via the Google embedding
           API (``gemini-embedding-2``) and inserted into Milvus in batches of
           100 with fault-tolerant retry logic.
Pass 2.5 : Index build — a single IVF_SQ8 index is built over all inserted
           vectors after Pass 2 completes. Building the index once avoids OOM
           spikes that occur when Milvus seals segments during incremental indexing.
Pass 3   : Graph edge scoring — ANN-based neighbour discovery. For each chunk,
           the top-11 nearest neighbours are retrieved and scored with a blended
           metric (0.60 × cosine + 0.25 × entity Jaccard + 0.15 × keyword Jaccard).
           Surviving neighbours are stored as ``"TYPE:chunk_id"`` strings, encoding
           the dominant edge type (PERSON | ORG | GPE | EVENT | NORP | semantic)
           without requiring a parallel array field.

Configuration
-------------
All service endpoints and model identifiers are defined as module-level
constants directly below the imports. To switch between local (DDEV) and cloud
(Google API / Zilliz) targets, change those constants only — no code changes
are required elsewhere.

Checkpoint / resume
-------------------
After Pass 1 the full chunk list (text + entities, no vectors) is pickled to
``.sage_chunks.pkl``. Pass 2 writes the index of the last successfully inserted
batch to ``.sage_progress.json`` after every batch. If the process is interrupted,
re-running with ``--resume`` skips Pass 1, loads the checkpoint, and continues
Pass 2 from the next unprocessed batch.

Usage
-----
Full rebuild::

    python sage_pipeline.py

Resume an interrupted build::

    python sage_pipeline.py --resume

Incremental update for a single Drupal node::

    python sage_pipeline.py --nid 42

The module is also imported by ``sage_service.py``, which exposes these
functions over HTTP for Drupal node-save hooks and Cloud Scheduler to invoke.
"""

import gc
import json
import os
import pickle
import time
from concurrent.futures import ThreadPoolExecutor, as_completed

import numpy
import requests
import spacy
from pymilvus import MilvusClient, DataType

# ── NLP model ─────────────────────────────────────────────────────────────────
# Loaded once at module import so the object is shared across every sync call
# handled by the Flask service process. en_core_web_lg gives the best NER
# accuracy for historical proper nouns; switch to en_core_web_sm if RAM is
# constrained (lower accuracy, ~300 MB vs ~700 MB).
_nlp = spacy.load("en_core_web_lg")

# Named-entity labels that are meaningful for civil-rights / historical content.
# All other spaCy labels are discarded during extraction.
ENTITY_TYPES = {"PERSON", "ORG", "GPE", "EVENT", "NORP", "FAC", "LOC"}

# ── Service endpoints ──────────────────────────────────────────────────────────
MILVUS_URI        = "http://localhost:19530"               # Milvus REST endpoint (DDEV)
DRUPAL_EXPORT_URL = "https://YOUR-DDEV-SITE.ddev.site/api/sage/export" # Drupal node export endpoint

# ── Google embedding configuration ────────────────────────────────────────────
GOOGLE_API_KEY    = os.getenv("GOOGLE_API_KEY", "")
EMBED_MODEL       = "gemini-embedding-2"
EMBED_BATCH_SLEEP = 0.5  # seconds between concurrent embed calls — increase if 429s return
EMBED_WORKERS     = 4    # concurrent embedding API calls — tune to your quota headroom
_GOOGLE_EMBED_URL   = f"https://generativelanguage.googleapis.com/v1beta/models/{EMBED_MODEL}:batchEmbedContents?key={GOOGLE_API_KEY}"
_GOOGLE_SINGLE_URL  = f"https://generativelanguage.googleapis.com/v1beta/models/{EMBED_MODEL}:embedContent?key={GOOGLE_API_KEY}"

# ── Milvus collection identifier ──────────────────────────────────────────────
COLLECTION_NAME = "sage_knowledge_graph"

# ── Checkpoint file paths ──────────────────────────────────────────────────────
# .sage_chunks.pkl     — chunk list after Pass 1 (no vectors). Resume anchor.
# .sage_progress.json  — last successfully inserted batch index for Pass 2.
# .sage_pass3a.pkl     — chunk_candidates dict + threshold from Pass 3a, so
#                        Pass 3b can resume without re-running the ANN scan.
# .sage_pass3b.json    — last completed Pass 3b batch index for mid-stream resume.
_SCRIPT_DIR     = os.path.dirname(os.path.abspath(__file__))
CHECKPOINT_FILE = os.path.join(_SCRIPT_DIR, ".sage_chunks.pkl")
PROGRESS_FILE   = os.path.join(_SCRIPT_DIR, ".sage_progress.json")
PASS3A_FILE     = os.path.join(_SCRIPT_DIR, ".sage_pass3a.pkl")
PASS3B_FILE     = os.path.join(_SCRIPT_DIR, ".sage_pass3b.json")

# ── Insert / fetch tuning ──────────────────────────────────────────────────────
# Reduce to 25–50 if Milvus OOMs during Pass 2 on lower-RAM machines.
BATCH_SIZE = 100

# ── Graph edge pruning ─────────────────────────────────────────────────────────
# Percentile of blended edge scores used as the Pass 3b cutoff. Lower values
# keep more edges (richer graph, more retrieval paths); higher values keep only
# the strongest connections (tighter graph, fewer false positives).
EDGE_PERCENTILE_THRESHOLD = 85.0

# ── Milvus client (lazy singleton) ────────────────────────────────────────────
# Initialised on first use rather than at import time so the Flask service can
# start and serve health checks even when Milvus is temporarily unreachable.
_client: MilvusClient | None = None


# ── Client helpers ─────────────────────────────────────────────────────────────

def get_client() -> MilvusClient:
    """Return the shared MilvusClient, creating it on first call.

    The client is module-level so it is reused across all pipeline functions
    within a single process. Call ``_client = None`` before ``get_client()``
    to force a fresh connection (used by the retry helper after a lost channel).

    Returns:
        The shared :class:`pymilvus.MilvusClient` instance pointed at
        ``MILVUS_URI``.
    """
    global _client
    if _client is None:
        _client = MilvusClient(uri=MILVUS_URI)
    return _client


def _get_dimension() -> int:
    """Probe the embedding model to discover its output dimensionality.

    Sends a single test string to the Google embedding API (``EMBED_MODEL``)
    and measures the length of the returned vector. Called once per sync run
    so the Milvus schema is always sized correctly regardless of which model
    is configured.

    Returns:
        The number of dimensions produced by the configured embedding model.

    Raises:
        Exception: If the Google API is unreachable or the key is invalid.
    """
    data = _google_post_with_retry(_GOOGLE_SINGLE_URL, {
        "model":    f"models/{EMBED_MODEL}",
        "content":  {"parts": [{"text": "Dimension Check"}]},
        "taskType": "RETRIEVAL_DOCUMENT",
    })
    return len(data["embedding"]["values"])


# ── Schema helpers ─────────────────────────────────────────────────────────────

def _build_schema(dimension: int) -> object:
    """Define the Milvus collection schema.

    Fields
    ------
    chunk_id
        Primary key. Format: ``nid_{drupal_nid}_c_{global_index}``.
    vector
        Dense float32 embedding produced by ``EMBED_MODEL``.
    text
        Raw chunk text, stored for retrieval display.
    source_nid
        Drupal node ID; used to delete/replace a node's chunks during an
        incremental sync.
    entities
        spaCy-extracted named entities stored as ``"TYPE:text"`` strings,
        e.g. ``"PERSON:Martin Luther King"``, ``"ORG:NAACP"``. Capped at
        150 items to stay within the Milvus ARRAY field limit. Storing the
        type prefix eliminates the need to re-run spaCy during Pass 3 edge
        classification.
    neighbors
        Graph-adjacent chunk IDs written by Pass 3, stored as
        ``"TYPE:chunk_id"`` strings, e.g. ``"PERSON:nid_1_c_5"``,
        ``"semantic:nid_2_c_10"``. The TYPE prefix encodes the dominant
        edge type (PERSON | ORG | GPE | EVENT | NORP | semantic) so no
        parallel array field is needed. ``max_length`` is 220 to accommodate
        the longest possible type prefix plus a full chunk_id.

    Note:
        ``age_range`` (VARCHAR) and ``keywords`` (ARRAY) are live schema fields
        populated from the Drupal ``field_age`` and ``field_keywords_plain``
        fields respectively. ``language`` is not included in this schema.

    Args:
        dimension: The vector dimensionality returned by ``_get_dimension()``.

    Returns:
        A configured :class:`pymilvus.CollectionSchema` ready to be passed
        to :meth:`MilvusClient.create_collection`.
    """
    schema = MilvusClient.create_schema(auto_id=False, enable_dynamic_field=False)
    schema.add_field("chunk_id",   DataType.VARCHAR,      max_length=100, is_primary=True)
    schema.add_field("vector",     DataType.FLOAT_VECTOR, dim=dimension)
    schema.add_field("text",       DataType.VARCHAR,      max_length=65535)
    schema.add_field("source_nid", DataType.INT64)
    schema.add_field("age_range",  DataType.VARCHAR, max_length=50)
    schema.add_field("keywords",   DataType.ARRAY, element_type=DataType.VARCHAR, max_capacity=30, max_length=200)
    schema.add_field("entities",   DataType.ARRAY, element_type=DataType.VARCHAR, max_capacity=150, max_length=200)
    schema.add_field("neighbors", DataType.ARRAY, element_type=DataType.VARCHAR, max_capacity=150, max_length=220)
    return schema


def _ensure_collection(client: MilvusClient, dimension: int) -> str:
    """Create the Milvus collection only if it does not already exist.

    Used on the ``--resume`` path where the collection must remain intact.
    No vector index is created here; Pass 2.5 builds IVF_SQ8 once after all
    inserts complete, which is far more memory-efficient than incremental
    index updates on each sealed segment.

    Args:
        client: The shared Milvus client.
        dimension: Vector dimensionality, passed to ``_build_schema``.

    Returns:
        The collection name (``COLLECTION_NAME``).
    """
    if not client.has_collection(COLLECTION_NAME):
        schema = _build_schema(dimension)
        client.create_collection(COLLECTION_NAME, schema=schema)
    return COLLECTION_NAME


def _rebuild_collection(client: MilvusClient, dimension: int) -> None:
    """Drop and unconditionally recreate the Milvus collection.

    Called only at the start of a full (non-resume) sync. Dropping the
    collection removes all existing vectors, scalar fields, and the index.
    No index is created here; see ``_ensure_collection`` for the rationale.

    Args:
        client: The shared Milvus client.
        dimension: Vector dimensionality, passed to ``_build_schema``.
    """
    if client.has_collection(COLLECTION_NAME):
        client.drop_collection(COLLECTION_NAME)
    schema = _build_schema(dimension)
    client.create_collection(COLLECTION_NAME, schema=schema)


# ── Checkpoint helpers ─────────────────────────────────────────────────────────

def _save_checkpoint(chunks: list) -> None:
    """Pickle the full chunk list to disk after Pass 1 completes.

    At save time, ``chunk['vector']`` is an empty list for every chunk — only
    ``text`` and ``entities`` are populated. The checkpoint lets Pass 2 be
    restarted from any batch without re-running the expensive spaCy pipeline.

    Args:
        chunks: The complete list of chunk dicts produced by ``_build_chunks``
            and annotated by ``_extract_entities``.
    """
    with open(CHECKPOINT_FILE, 'wb') as f:
        pickle.dump(chunks, f)
    print(f"  Checkpoint saved: {len(chunks)} chunks → {CHECKPOINT_FILE}")


def _load_checkpoint() -> list:
    """Load the chunk list saved by ``_save_checkpoint``.

    Returns:
        The unpickled list of chunk dicts. Vectors will be empty lists;
        Pass 2 will populate them before inserting into Milvus.

    Raises:
        FileNotFoundError: If the checkpoint file does not exist.
    """
    with open(CHECKPOINT_FILE, 'rb') as f:
        return pickle.load(f)


def _save_progress(last_completed_batch: int) -> None:
    """Write the last successfully inserted batch index to disk.

    Called after every batch in Pass 2. On a crash, the resume path reads
    this file to skip already-inserted batches.

    Args:
        last_completed_batch: Zero-based index of the most recently completed
            batch. Pass 2 resumes from ``last_completed_batch + 1``.
    """
    with open(PROGRESS_FILE, 'w') as f:
        json.dump({"last_completed_batch": last_completed_batch}, f)


def _load_progress() -> int:
    """Return the last completed batch index from the progress file.

    Returns:
        The last completed batch index, or ``-1`` if the progress file does
        not exist (meaning Pass 2 has not started yet).
    """
    if os.path.exists(PROGRESS_FILE):
        with open(PROGRESS_FILE) as f:
            return json.load(f).get("last_completed_batch", -1)
    return -1


def _clear_checkpoint() -> None:
    """Delete all four checkpoint files after a fully successful sync.

    Only called when Pass 3 confirms that at least one chunk received a
    neighbour. If Pass 3 produces zero neighbours the files are preserved so
    the threshold can be investigated and Pass 3 re-run via ``--resume``
    without losing the embedding work from Pass 2.
    """
    for path in [CHECKPOINT_FILE, PROGRESS_FILE, PASS3A_FILE, PASS3B_FILE]:
        if os.path.exists(path):
            os.remove(path)


# ── Drupal data helpers ────────────────────────────────────────────────────────

def _fetch_nodes(nid: int | None = None) -> list:
    """Fetch node data from Drupal's SAGE export endpoint.

    Args:
        nid: If provided, only the node with this Drupal node ID is returned
            (used by the incremental sync path). If ``None``, all published
            nodes are returned.

    Returns:
        A list of node dicts, each containing at minimum ``nid`` and ``body``
        keys as produced by the Drupal export controller.

    Note:
        ``verify=False`` suppresses the TLS certificate warning from DDEV's
        self-signed certificate. Remove this flag in production where a valid
        certificate is present.
    """
    url      = DRUPAL_EXPORT_URL + (f"?nid={nid}" if nid else "")
    response = requests.get(url, verify=False)
    data     = response.json()
    print(f"Fetched {len(data)} node(s) from Drupal export.")
    return data


def _build_chunks(nodes: list) -> list:
    """Split node body text into overlapping fixed-size windows.

    Each node's body is split on whitespace and windowed with a size of 120
    words and a stride of 100 words, giving 20 words of overlap between
    adjacent chunks. Windows shorter than 10 characters after stripping are
    discarded.

    ``chunk_idx`` is a global counter (not reset per node) so chunk IDs are
    unique across the entire collection even if a node is re-chunked with
    different content.

    Args:
        nodes: List of node dicts from ``_fetch_nodes``, each with at minimum
            ``nid`` (int) and ``body`` (str) keys.

    Returns:
        A list of chunk dicts. Each dict has the following keys:

        - ``chunk_id`` (str): Unique identifier in ``nid_{nid}_c_{idx}`` format.
        - ``source_nid`` (int): The originating Drupal node ID.
        - ``text`` (str): The raw chunk text.
        - ``entities`` (list): Empty list; populated by ``_extract_entities``.
        - ``vector`` (list): Empty list; populated by ``_generate_vectors``.
        - ``neighbors`` (list): Empty list; populated by Pass 3.

    Note:
        ``age_range`` and ``keywords`` are populated from the Drupal export's
        ``age_range`` and ``keywords`` keys, which map to ``field_age`` and
        ``field_keywords_plain`` respectively.
    """
    chunks    = []
    chunk_idx = 0
    for node in nodes:
        words = node['body'].split()
        for i in range(0, len(words), 100):
            text = " ".join(words[i:i + 120])
            if len(text.strip()) > 10:
                chunks.append({
                    "chunk_id":   f"nid_{node['nid']}_c_{chunk_idx}",
                    "source_nid": node['nid'],
                    "text":       text,
                    "age_range":  node.get('age_range', ''),
                    "keywords":   [str(kw)[:200] for kw in node.get('keywords', [])][:30],
                    "entities":   [],
                    "vector":     [],
                    "neighbors":  [],
                })
                chunk_idx += 1
    return chunks


# ── Query NER (used by /ner service endpoint) ──────────────────────────────────

def extract_query_entity_types(text: str) -> list[str]:
    """Run spaCy NER on a query string and return the unique entity type labels.

    Uses the same ``_nlp`` model and ``ENTITY_TYPES`` filter as Pass 1, so
    the detected types align with the edge-type labels stored in the graph.
    This consistency is what makes query-time edge-type weighting meaningful.

    Typically completes in under 20 ms for query-length strings because the
    model is already loaded in memory.

    Args:
        text: The raw user query string. Not lowercased before processing;
            spaCy relies on capitalisation for accurate NER.

    Returns:
        A deduplicated list of entity type label strings present in ``text``,
        e.g. ``["PERSON", "ORG"]``. Returns an empty list if no recognised
        entities are found.
    """
    doc = _nlp(text)
    return list({ent.label_ for ent in doc.ents if ent.label_ in ENTITY_TYPES})


# ── Pass 1: Entity extraction ──────────────────────────────────────────────────

def _extract_entities(chunks: list, batch_size: int = 512) -> None:
    """Run spaCy NER over all chunks and populate ``chunk['entities']``.

    Processes chunks through ``nlp.pipe()`` in batches for memory-efficient
    streaming. Only labels present in ``ENTITY_TYPES`` are retained.

    Entities are stored as ``"TYPE:text"`` strings rather than plain text so
    that ``_classify_edge_type`` in Pass 3 can determine the edge type without
    re-running spaCy over the stored text. The PHP retriever strips the prefix
    before passing entities to the AI agent.

    A set comprehension deduplicates within each chunk before the 150-item cap
    is applied. ``gc.collect()`` is called after the loop because spaCy's
    pipeline retains references to ``Doc`` objects; forcing collection frees
    that memory before the embedding pass begins.

    Args:
        chunks: The chunk list produced by ``_build_chunks``. Mutated in place;
            ``chunk['entities']`` is populated for every chunk.
        batch_size: Number of texts passed to ``nlp.pipe()`` per iteration.
            Higher values improve throughput but increase peak RAM usage.
            Defaults to 512.
    """
    total = len(chunks)
    print(f"Pass 1: Extracting entities for {total} chunks via spaCy (batch_size={batch_size})...")
    texts = [chunk['text'] for chunk in chunks]
    for i, doc in enumerate(_nlp.pipe(texts, batch_size=batch_size)):
        chunks[i]['entities'] = list({
            f"{ent.label_}:{ent.text}" for ent in doc.ents if ent.label_ in ENTITY_TYPES
        })[:150]
        if (i + 1) % 5000 == 0:
            print(f"  [{i + 1}/{total}] entities extracted ({(i + 1) / total * 100:.1f}%)...", flush=True)
    print(f"  Entity extraction complete ({total} chunks).")
    gc.collect()


def _export_corpus_signals(chunks: list, top_n: int = 50) -> None:
    """Tally entity names per type across all chunks and write sage_corpus_signals.json.

    Called after ``_extract_entities`` so the PHP retriever can detect query
    intent without a Python sidecar. The file is imported into Drupal's
    key-value store via the ``sage:import-signals`` Drush command.

    Args:
        chunks: Chunk list with ``entities`` populated by ``_extract_entities``.
        top_n:  Maximum entity names to export per type.
    """
    from collections import Counter

    counts: dict[str, Counter] = {}
    for chunk in chunks:
        for encoded in chunk.get('entities', []):
            if ':' not in encoded:
                continue
            etype, name = encoded.split(':', 1)
            counts.setdefault(etype, Counter())[name] += 1

    signals: dict[str, list[str]] = {
        etype: [name for name, _ in counter.most_common(top_n)]
        for etype, counter in counts.items()
    }

    out_path = os.path.join(_SCRIPT_DIR, 'sage_corpus_signals.json')
    with open(out_path, 'w') as fh:
        json.dump(signals, fh, indent=2)
    total = sum(len(v) for v in signals.values())
    print(f"Corpus signals written to {out_path} ({total} entries across {len(signals)} types).")


# ── Pass 2: Embedding ──────────────────────────────────────────────────────────

def _generate_vectors(chunks: list, dimension: int) -> None:
    """Embed each chunk's text via the Google embedding API and store the result in ``chunk['vector']``.

    Sends all chunk texts in a single batched call to the Google embedding
    API, which is far more efficient than one call per chunk. The
    ``gemini-embedding-2`` model accepts up to 100 texts per request.

    If the batch call fails, falls back to embedding one chunk at a time so
    that a single bad chunk does not abort the entire batch. Zero-vectors are
    used for any chunk that still fails individually — they have cosine
    similarity 0 against every real vector and will never appear as neighbours
    in Pass 3.

    Args:
        chunks: A slice of the full chunk list. Mutated in place;
            ``chunk['vector']`` is populated for every chunk.
        dimension: The embedding dimension from ``_get_dimension()``, used to
            construct the zero-vector fallback.
    """
    texts = [chunk['text'] for chunk in chunks]
    try:
        data = _google_post_with_retry(_GOOGLE_EMBED_URL, {
            "requests": [
                {
                    "model":    f"models/{EMBED_MODEL}",
                    "content":  {"parts": [{"text": t}]},
                    "taskType": "RETRIEVAL_DOCUMENT",
                }
                for t in texts
            ]
        })
        for chunk, emb in zip(chunks, data["embeddings"]):
            chunk['vector'] = emb["values"]
        time.sleep(EMBED_BATCH_SLEEP)
    except Exception as e:
        print(f"  Batch embedding failed ({e}), falling back to single-chunk embedding...")
        for chunk in chunks:
            try:
                data = _google_post_with_retry(_GOOGLE_SINGLE_URL, {
                    "model":    f"models/{EMBED_MODEL}",
                    "content":  {"parts": [{"text": chunk['text']}]},
                    "taskType": "RETRIEVAL_DOCUMENT",
                })
                chunk['vector'] = data["embedding"]["values"]
            except Exception as e2:
                print(f"  Embedding failed for {chunk['chunk_id']}: {e2}")
                chunk['vector'] = [0.0] * dimension


# ── Google API resilience helper ──────────────────────────────────────────────

def _google_post_with_retry(url: str, payload: dict, max_retries: int = 6) -> dict:
    """POST to a Google API endpoint with exponential backoff on 429 rate limits.

    Args:
        url: Full URL including API key query parameter.
        payload: JSON-serialisable request body.
        max_retries: Maximum attempts before re-raising. Defaults to 6
            (covers up to 32 s of backoff — enough for a free-tier quota reset).

    Returns:
        Parsed JSON response dict.

    Raises:
        requests.exceptions.HTTPError: On non-429 HTTP errors or exhausted retries.
    """
    for attempt in range(max_retries):
        response = requests.post(url, json=payload)
        if response.status_code == 429:
            wait = 2 ** attempt  # 1 s, 2 s, 4 s, 8 s, 16 s, 32 s
            print(f"  Rate limited by Google API, retrying in {wait}s (attempt {attempt + 1}/{max_retries})...", flush=True)
            time.sleep(wait)
            continue
        response.raise_for_status()
        return response.json()
    raise RuntimeError(f"Google API rate limit not resolved after {max_retries} retries.")


# ── Milvus resilience helpers ──────────────────────────────────────────────────

def _is_milvus_connection_error(err: str) -> bool:
    """Return True if the lowercased error string indicates a transient Milvus connection failure."""
    return any(kw in err for kw in ("recovering", "closed channel", "unavailable", "connecting"))


def _wait_for_milvus(max_wait: int = 180) -> bool:
    """Poll the Milvus health endpoint until it responds or the timeout expires.

    Used by ``_milvus_insert_with_retry`` to pause automatically when Milvus
    crashes and is being restarted by Docker's restart policy.

    Args:
        max_wait: Maximum number of seconds to wait before returning ``False``.
            Defaults to 180 (3 minutes).

    Returns:
        ``True`` if Milvus returned HTTP 200 within ``max_wait`` seconds,
        ``False`` if it did not recover in time.
    """
    deadline = time.time() + max_wait
    while time.time() < deadline:
        try:
            r = requests.get("http://localhost:9091/healthz", timeout=5)
            if r.status_code == 200:
                return True
        except Exception:
            pass
        print("  Waiting for Milvus to recover...", flush=True)
        time.sleep(10)
    return False


def _milvus_insert_with_retry(client: MilvusClient, batch: list, max_retries: int = 5) -> None:
    """Insert a batch of chunk dicts into Milvus with fault-tolerant retry logic.

    Handles two distinct failure modes:

    - **Connection errors** (closed channel, UNAVAILABLE): the client is
      discarded and ``_wait_for_milvus`` polls until Milvus is healthy again
      before retrying. This covers the case where Milvus OOMs and is restarted
      by Docker.
    - **Other errors** (schema violations, quota exceeded): exponential backoff
      is applied — 10 s, 20 s, 40 s, 80 s between attempts.

    A 1-second sleep after a successful insert gives Milvus time to flush the
    WAL and release segment memory before the next batch arrives, reducing the
    risk of OOM crashes on the Milvus container.

    Args:
        client: The shared Milvus client (ignored after a connection error;
            ``get_client()`` creates a fresh one).
        batch: List of chunk dicts ready for insertion. Each dict must contain
            all schema fields with the correct types.
        max_retries: Maximum number of insert attempts before re-raising the
            last exception. Defaults to 5.

    Raises:
        Exception: Re-raises the last exception if all ``max_retries`` attempts
            fail, or if ``_wait_for_milvus`` times out.
    """
    global _client
    for attempt in range(max_retries):
        try:
            get_client().insert(COLLECTION_NAME, batch)
            time.sleep(1)
            return
        except Exception as e:
            if attempt == max_retries - 1:
                raise
            _client = None
            err = str(e).lower()
            if _is_milvus_connection_error(err):
                print(f"  Milvus connection lost (attempt {attempt + 1}/{max_retries}). Waiting for recovery...", flush=True)
                if not _wait_for_milvus(max_wait=180):
                    raise RuntimeError("Milvus did not recover within 3 minutes.") from e
                print("  Milvus recovered. Retrying insert...", flush=True)
            else:
                wait = 10 * (2 ** attempt)
                print(f"  Insert failed (attempt {attempt + 1}/{max_retries}), retrying in {wait}s: {e}", flush=True)
                time.sleep(wait)


# ── Milvus read resilience helper ─────────────────────────────────────────────

def _milvus_op_with_retry(fn, *args, max_retries: int = 6, **kwargs):
    """Call a Milvus read/search operation with recovery-aware retry.

    Handles the "collection on recovering" error that Milvus emits when it
    restarts (e.g. after a Docker OOM kill or disk pressure event). The helper
    waits up to 5 minutes for Milvus to come back, reloads the collection, and
    retries. Other errors are re-raised immediately.

    Args:
        fn: Bound method to call (e.g. ``get_client().get``).
        *args: Positional arguments forwarded to ``fn``.
        max_retries: Maximum attempts before re-raising.
        **kwargs: Keyword arguments forwarded to ``fn``.

    Returns:
        Whatever ``fn`` returns on success.
    """
    global _client
    for attempt in range(max_retries):
        try:
            return fn(*args, **kwargs)
        except Exception as e:
            if attempt == max_retries - 1:
                raise
            err = str(e).lower()
            if _is_milvus_connection_error(err):
                print(f"  Milvus in recovery (attempt {attempt + 1}/{max_retries}). Waiting up to 5 min...", flush=True)
                _client = None
                if not _wait_for_milvus(max_wait=300):
                    raise RuntimeError("Milvus did not recover within 5 minutes.") from e
                get_client().load_collection(COLLECTION_NAME)
                print("  Milvus recovered. Retrying...", flush=True)
            else:
                raise


# ── Pass 3: Graph edge scoring ─────────────────────────────────────────────────

def _jaccard(a: list, b: list) -> float:
    """Compute Jaccard similarity between two entity lists.

    Jaccard similarity is defined as the size of the intersection divided by
    the size of the union of two sets. Used as the entity-overlap component of
    the blended SAGE edge score.

    Args:
        a: First entity list. Elements are ``"TYPE:text"`` strings.
        b: Second entity list. Elements are ``"TYPE:text"`` strings.

    Returns:
        A float in ``[0.0, 1.0]``. Returns ``0.0`` if either list is empty
        to avoid division by zero.
    """
    sa, sb = set(a), set(b)
    if not sa or not sb:
        return 0.0
    return len(sa & sb) / len(sa | sb)


def _edge_score(cosine: float, entities_a: list, entities_b: list, keywords_a: list = None, keywords_b: list = None) -> float:
    """Compute the blended SAGE edge score between two chunks.

    The score combines semantic similarity (cosine) with structural overlap
    (entity Jaccard). Cosine similarity rewards chunks that discuss the same
    concepts in similar language; entity Jaccard rewards chunks that share
    named entities even when their embedding similarity is moderate — for
    example, two chunks about different events involving the same person.

    Score formula::

        score = 0.60 × cosine_similarity + 0.25 × entity_jaccard + 0.15 × keyword_jaccard

    The fixed threshold of 0.55 used in ``_build_neighbors_via_milvus`` was
    derived empirically from the score distribution of this corpus. A
    percentile-based threshold (as used in the reference Java SAGE
    implementation) would be more adaptive but requires collecting the full
    score distribution before pruning, which our ANN-based Pass 3 supports
    at the cost of a two-pass approach.

    Args:
        cosine: Cosine similarity from Milvus ANN search (``hit['distance']``
            with ``COSINE`` metric). Range: ``[0.0, 1.0]``.
        entities_a: Typed entity list for the first chunk (``"TYPE:text"`` format).
        entities_b: Typed entity list for the second chunk (``"TYPE:text"`` format).
        keywords_a: Keyword list for the first chunk. Defaults to empty list.
        keywords_b: Keyword list for the second chunk. Defaults to empty list.

    Returns:
        Blended edge score as a float. Values at or above the threshold
        (default 0.55) are retained as graph edges.
    """
    return (0.60 * cosine) + (0.25 * _jaccard(entities_a, entities_b)) + (0.15 * _jaccard(keywords_a or [], keywords_b or []))


def _classify_edge_type(entities_a: list, entities_b: list) -> str:
    """Determine the dominant edge type from the overlapping typed entities.

    Parses both entity lists from ``"TYPE:text"`` format, finds entity texts
    that appear in both lists (case-insensitive), and returns the type of the
    highest-priority overlapping entity. Falls back to ``'semantic'`` when
    there is no entity overlap — indicating a pure cosine-similarity edge.

    Priority order: PERSON > EVENT > NORP > ORG > GPE > semantic.

    FAC (facility) and LOC (generic location) are intentionally excluded from
    the priority list because they are too generic to reliably signal query
    intent; edges driven only by FAC or LOC overlap are classified as
    ``'semantic'``.

    Args:
        entities_a: Typed entity list for the first chunk in ``"TYPE:text"``
            format, as stored in the Milvus ``entities`` field.
        entities_b: Typed entity list for the second chunk.

    Returns:
        One of ``'PERSON'``, ``'EVENT'``, ``'NORP'``, ``'ORG'``, ``'GPE'``,
        or ``'semantic'``.
    """
    if not entities_a or not entities_b:
        return 'semantic'

    def parse(lst: list) -> dict:
        out = {}
        for e in lst:
            if ':' in e:
                t, text = e.split(':', 1)
                out[text.lower()] = t
        return out

    map_a = parse(entities_a)
    map_b = parse(entities_b)

    overlap_texts = set(map_a.keys()) & set(map_b.keys())
    if not overlap_texts:
        return 'semantic'

    overlap_types = {map_a[t] for t in overlap_texts}

    for t in ('PERSON', 'EVENT', 'NORP', 'ORG', 'GPE'):
        if t in overlap_types:
            return t
    return 'semantic'


def _build_neighbors_via_milvus(client: MilvusClient, chunks: list, percentile: float = 95.0) -> int:
    """Populate ``neighbors`` using two-pass percentile-based pruning.

    Pass 3a — Score collection
        ANN search is run over all chunks. Every candidate blended score is
        collected into a flat list alongside the pre-classified edge string.
        No threshold is applied here; all top-10 neighbours are recorded.

    Percentile computation
        numpy computes the ``percentile``-th percentile of the full score
        distribution. This is corpus-relative and model-agnostic — it adapts
        automatically when the embedding model is changed, unlike a fixed value
        that was calibrated against a specific model's similarity distribution.

    Pass 3b — Pruning and upsert
        Each chunk's candidate list is filtered against the computed threshold.
        Surviving candidates are written as ``"TYPE:chunk_id"`` strings and
        upserted back to Milvus in batches.

    Memory note
        Storing ~1.16 M candidate records (116 k chunks × 10 neighbours) uses
        roughly 50 MB — well within available RAM for this corpus size.

    Args:
        client: The shared Milvus client.
        chunks: The complete in-memory chunk list. Used for chunk IDs and
            candidate lookup; records are fetched directly from Milvus in
            Pass 3b to avoid holding vectors in memory.
        percentile: Score percentile used as the pruning threshold.
            Defaults to 95.0, retaining only the top 5 % of candidate edges.

    Returns:
        The number of chunks that received at least one neighbour after pruning.
        Returns 0 if no candidate scores were collected.
    """
    total = len(chunks)

    # ── Pass 3a: collect all candidate scores (skip if checkpoint exists) ──
    if os.path.exists(PASS3A_FILE):
        print(f"  Pass 3a checkpoint found — loading scores and threshold from disk...")
        with open(PASS3A_FILE, 'rb') as f:
            checkpoint_data = pickle.load(f)
        chunk_candidates: dict[str, list[tuple[float, str]]] = checkpoint_data['chunk_candidates']
        threshold = float(checkpoint_data['threshold'])
        print(f"  Loaded {len(chunk_candidates):,} chunk candidates. "
              f"Threshold: {threshold:.4f}")
    else:
        print(f"  Pass 3a: Collecting candidate scores for {total} chunks...")
        all_scores:      list[float] = []
        chunk_candidates = {}

        for batch_start in range(0, total, BATCH_SIZE):
            batch = chunks[batch_start:batch_start + BATCH_SIZE]
            ids   = [c['chunk_id'] for c in batch]

            stored     = _milvus_op_with_retry(
                get_client().get, COLLECTION_NAME, ids,
                output_fields=["chunk_id", "vector", "entities", "keywords"],
            )
            stored_map = {r['chunk_id']: r for r in stored}

            valid_chunk_records = [
                (chunk, stored_map[chunk['chunk_id']])
                for chunk in batch
                if chunk['chunk_id'] in stored_map and stored_map[chunk['chunk_id']].get('vector')
            ]

            if not valid_chunk_records:
                continue

            query_vectors = [record['vector'] for _, record in valid_chunk_records]

            all_results = _milvus_op_with_retry(
                get_client().search,
                collection_name=COLLECTION_NAME,
                data=query_vectors,
                anns_field="vector",
                search_params={"metric_type": "COSINE"},
                limit=11,
                output_fields=["chunk_id", "entities", "keywords"],
            )

            for (chunk, record), hits in zip(valid_chunk_records, all_results):
                chunk_entities = record.get('entities', [])
                chunk_keywords = record.get('keywords', [])
                candidates: list[tuple[float, str]] = []

                for hit in hits:
                    if hit['entity']['chunk_id'] == chunk['chunk_id']:
                        continue
                    score     = _edge_score(
                        hit['distance'],
                        chunk_entities,
                        hit['entity'].get('entities', []),
                        chunk_keywords,
                        hit['entity'].get('keywords', []),
                    )
                    edge_type = _classify_edge_type(chunk_entities, hit['entity'].get('entities', []))
                    candidates.append((score, f"{edge_type}:{hit['entity']['chunk_id']}"))
                    all_scores.append(score)

                chunk_candidates[chunk['chunk_id']] = candidates

            if (batch_start + BATCH_SIZE) % 10000 == 0:
                print(f"  [{batch_start + BATCH_SIZE}/{total}] scores collected...", flush=True)

        if not all_scores:
            print("WARNING: No candidate scores collected — ANN search returned no results.")
            return 0

        threshold = float(numpy.percentile(all_scores, percentile))
        print(f"  Collected {len(all_scores):,} candidate scores. "
              f"{percentile}th-percentile threshold: {threshold:.4f} "
              f"(min={min(all_scores):.4f}, max={max(all_scores):.4f})")

        with open(PASS3A_FILE, 'wb') as f:
            pickle.dump({'chunk_candidates': chunk_candidates, 'threshold': threshold}, f)
        print(f"  Pass 3a checkpoint saved.")

        config_path = os.path.join(_SCRIPT_DIR, 'sage_graph_config.json')
        with open(config_path, 'w') as f:
            json.dump({'edge_threshold': threshold}, f)
        print(f"  Edge threshold written to {config_path}.")

    # ── Pass 3b: prune and upsert (resumable) ─────────────────────────────
    # Fetch full records from Milvus in batches (vectors were not kept in memory
    # during Pass 3a to avoid OOM on large corpora). Only the neighbors field is
    # changed; all other fields including vector are written back as-is.
    pass3b_start = 0
    if os.path.exists(PASS3B_FILE):
        with open(PASS3B_FILE) as f:
            pass3b_start = (json.load(f).get('last_completed_batch', -1) + 1) * BATCH_SIZE
        print(f"  Pass 3b: Resuming from position {pass3b_start:,}...")
    else:
        print(f"  Pass 3b: Applying threshold and writing neighbors...")

    ids_with_candidates   = [c['chunk_id'] for c in chunks if c['chunk_id'] in chunk_candidates]
    total_to_write        = len(ids_with_candidates)
    chunks_with_neighbors = 0

    for batch_start in range(pass3b_start, total_to_write, BATCH_SIZE):
        batch_ids = ids_with_candidates[batch_start:batch_start + BATCH_SIZE]
        stored    = _milvus_op_with_retry(
            get_client().get,
            COLLECTION_NAME, batch_ids,
            output_fields=["chunk_id", "vector", "text", "source_nid",
                           "age_range", "entities", "keywords"],
        )
        upsert_batch: list = []
        for record in stored:
            cid = record['chunk_id']
            neighbors = [
                encoded for score, encoded in chunk_candidates.get(cid, [])
                if score >= threshold
            ]
            record['neighbors'] = neighbors
            record['keywords']  = [str(kw)[:200] for kw in record.get('keywords', [])][:30]
            record['entities']  = [str(e)[:200]  for e in record.get('entities', [])][:150]
            upsert_batch.append(record)
            if neighbors:
                chunks_with_neighbors += 1

        if upsert_batch:
            client.upsert(COLLECTION_NAME, upsert_batch)

        with open(PASS3B_FILE, 'w') as f:
            json.dump({'last_completed_batch': batch_start // BATCH_SIZE}, f)

        if (batch_start + BATCH_SIZE) % 10000 == 0 or batch_start + BATCH_SIZE >= total_to_write:
            print(f"  [{min(batch_start + BATCH_SIZE, total_to_write)}/{total_to_write}] "
                  f"neighbors written...", flush=True)

    print(f"  Neighbor pass complete. {chunks_with_neighbors:,}/{total:,} chunks have neighbors "
          f"(threshold={threshold:.4f}).")
    return chunks_with_neighbors


def _build_incremental_neighbors(client: MilvusClient, chunks: list, dimension: int, threshold: float = 0.55) -> None:
    """Build graph edges for a small set of newly inserted chunks.

    Unlike ``_build_neighbors_via_milvus``, this function operates on in-memory
    vectors freshly generated by ``_generate_vectors`` rather than fetching them
    back from Milvus. It is used by the incremental sync path where the new
    chunks' vectors are already in memory and the collection is already loaded
    and indexed.

    Each new chunk is searched against the full existing collection to find its
    neighbours. The new chunks themselves are not yet in the collection at search
    time, so self-exclusion is not necessary.

    Args:
        client: The shared Milvus client. The collection must be loaded and
            indexed before this function is called.
        chunks: The newly created chunk dicts for a single Drupal node. Each
            must have ``vector`` and ``entities`` populated. ``chunk['neighbors']``
            is populated in place.
        dimension: The embedding dimension. Currently unused in the function body
            but retained in the signature for consistency with the full-sync API
            and forward compatibility with percentile-based threshold calculation.
        threshold: Minimum blended edge score for a neighbour to be retained.
            Defaults to 0.55.

    Note:
        ``keywords`` is included in ``output_fields`` and passed to ``_edge_score``
        alongside entities to compute the blended edge score.
    """
    for chunk in chunks:
        results = client.search(
            collection_name=COLLECTION_NAME,
            data=[chunk['vector']],
            anns_field="vector",
            search_params={"metric_type": "COSINE"},
            limit=11,
            output_fields=["chunk_id", "entities", "keywords"],
        )
        qualifying = [
            hit
            for hits in results
            for hit in hits
            if hit['entity']['chunk_id'] != chunk['chunk_id']
            and _edge_score(
                hit['distance'],
                chunk['entities'],
                hit['entity'].get('entities', []),
                chunk.get('keywords', []),
                hit['entity'].get('keywords', []),
            ) >= threshold
        ]
        chunk['neighbors'] = [
            f"{_classify_edge_type(chunk['entities'], h['entity'].get('entities', []))}:{h['entity']['chunk_id']}"
            for h in qualifying
        ]


# ── Orchestration ──────────────────────────────────────────────────────────────

def run_full_sync(resume: bool = False, skip_index: bool = False) -> None:
    """Run the complete SAGE graph build pipeline.

    Executes Pass 1 → Pass 2 → Pass 2.5 → Pass 3 in sequence, with checkpoint
    and resume support for recovering from interruptions during Pass 2.

    Pass summary
    ------------
    Pass 1
        Extract named entities from all corpus chunks via spaCy and store them
        as ``"TYPE:text"`` strings. Result is checkpointed to disk.
    Pass 2
        Embed each chunk via the Google embedding API and insert into Milvus
        in batches of 100. Progress is written to disk after every batch.
    Pass 2.5
        Build a single IVF_SQ8 vector index over all inserted chunks. IVF_SQ8
        quantises float32 vectors to int8, reducing index RAM by ~8× versus
        IVF_FLAT with negligible recall loss (~1–2%) at this corpus size.
    Pass 3
        For each chunk, find its top-10 nearest neighbours via batched ANN
        search, score each with ``_edge_score``, classify the dominant edge type
        with ``_classify_edge_type``, and write ``"TYPE:chunk_id"`` strings back
        to the ``neighbors`` field via upsert.

    Resume behaviour
    ----------------
    If ``--resume`` is passed and ``.sage_chunks.pkl`` exists, Pass 1 is skipped
    and Pass 2 continues from the batch recorded in ``.sage_progress.json``.

    If ``--resume`` is passed but no checkpoint exists and the collection already
    contains data, the function aborts with a SAFETY STOP rather than silently
    destroying existing embeddings.

    Checkpoint lifecycle
    --------------------
    Saved after Pass 1 (vectors are empty at this point).
    Updated after each batch in Pass 2 (progress file only).
    Deleted after Pass 3 completes with at least one neighbour. If Pass 3
    produces zero neighbours, the checkpoint is preserved so the threshold can
    be adjusted and Pass 3 re-run via ``--resume`` without redoing Pass 2.

    Args:
        resume: If ``True``, attempt to resume from an existing checkpoint
            rather than rebuilding from scratch. Defaults to ``False``.
        skip_index: If ``True``, skip Pass 2.5 index rebuild and simply reload
            the existing collection. Use when resuming Pass 3 after a crash
            where the IVF_SQ8 index is already built and valid.
    """
    client    = get_client()
    dimension = _get_dimension()
    print(f"Embedding dimension: {dimension}")

    if resume and os.path.exists(CHECKPOINT_FILE):
        print("Resuming from checkpoint — skipping fetch, chunking, and entity extraction...")
        chunks               = _load_checkpoint()
        last_completed_batch = _load_progress()
        start_batch          = last_completed_batch + 1
        print(f"Loaded {len(chunks)} chunks. Resuming from batch {start_batch} "
              f"(chunk {start_batch * BATCH_SIZE}).")
        _ensure_collection(client, dimension)
    else:
        if resume:
            if client.has_collection(COLLECTION_NAME):
                stats     = client.get_collection_stats(COLLECTION_NAME)
                row_count = int(stats.get('row_count', 0))
                if row_count > 0:
                    print(f"SAFETY STOP: No checkpoint found, but the collection already has "
                          f"{row_count} rows of embedded data.")
                    print("Re-run without --resume to intentionally rebuild from scratch.")
                    print("If the checkpoint was accidentally deleted, it cannot be recovered.")
                    return
            print("No checkpoint found — starting full sync from scratch.")
        else:
            print("Starting full structural matrix rebuild...")

        _rebuild_collection(client, dimension)
        nodes  = _fetch_nodes()
        chunks = _build_chunks(nodes)

        if not chunks:
            print("No nodes found.")
            return

        print(f"Total chunks to process: {len(chunks)}")
        _extract_entities(chunks)
        _export_corpus_signals(chunks)
        _save_checkpoint(chunks)
        last_completed_batch = -1
        start_batch          = 0

    # ── Pass 2: Embedding + insert ─────────────────────────────────────────────
    # Batches are embedded EMBED_WORKERS at a time using a thread pool (network
    # I/O-bound), then inserted into Milvus sequentially to avoid WAL pressure.
    total       = len(chunks)
    all_batches = [
        (start_batch + i, chunks[s:s + BATCH_SIZE])
        for i, s in enumerate(range(start_batch * BATCH_SIZE, total, BATCH_SIZE))
    ]
    print(f"Pass 2: Generating embeddings and inserting in batches "
          f"(starting batch {start_batch}, {EMBED_WORKERS} concurrent workers)...")

    for window_start in range(0, len(all_batches), EMBED_WORKERS):
        window = all_batches[window_start:window_start + EMBED_WORKERS]

        with ThreadPoolExecutor(max_workers=len(window)) as executor:
            futures = {
                executor.submit(_generate_vectors, batch, dimension): (actual_num, batch)
                for actual_num, batch in window
            }
            for future in as_completed(futures):
                future.result()  # propagate any embedding exception

        for actual_batch_num, batch in window:
            batch_start_idx = actual_batch_num * BATCH_SIZE
            for chunk in batch:
                chunk['keywords'] = [str(kw)[:200] for kw in chunk.get('keywords', [])][:30]
                chunk['entities'] = [e[:200] for e in chunk.get('entities', [])][:150]
            _milvus_insert_with_retry(get_client(), batch)
            _save_progress(actual_batch_num)
            print(f"  Inserted chunks {batch_start_idx + 1}–{batch_start_idx + len(batch)} of {total} "
                  f"(batch {actual_batch_num})", flush=True)
            if actual_batch_num % 10 == 0:
                get_client().flush(COLLECTION_NAME)
                print(f"  Flushed collection at batch {actual_batch_num}.", flush=True)

    # ── Pass 2.5: Index build ──────────────────────────────────────────────────
    client = get_client()
    if skip_index:
        print("Pass 2.5: Skipping index rebuild (--skip-index). Reloading existing collection...")
        try:
            client.load_collection(COLLECTION_NAME)
        except Exception:
            pass  # Already loaded — that is fine.
        print("  Collection reloaded.")
    else:
        print("Pass 2.5: Rebuilding vector index (IVF_SQ8) over full dataset...")
        client.flush(COLLECTION_NAME)
        try:
            client.release_collection(COLLECTION_NAME)
        except Exception:
            pass  # Not loaded — that is fine.
        try:
            client.drop_index(COLLECTION_NAME, "vector")
        except Exception:
            pass  # No index present — that is fine.
        index_params = client.prepare_index_params()
        index_params.add_index("vector", index_type="IVF_SQ8", metric_type="COSINE", params={"nlist": 128})
        client.create_index(COLLECTION_NAME, index_params=index_params)
        client.load_collection(COLLECTION_NAME)
        print("  Index rebuild complete.")

    # ── Pass 3: Neighbor graph ─────────────────────────────────────────────────
    print("Pass 3: Computing neighbors via two-pass percentile pruning...")
    chunks_with_neighbors = _build_neighbors_via_milvus(get_client(), chunks, percentile=EDGE_PERCENTILE_THRESHOLD)

    if chunks_with_neighbors == 0:
        print("WARNING: No chunks have neighbors — threshold may be too high or Pass 3 failed.")
        print("Checkpoint preserved. Investigate before re-running.")
    else:
        _clear_checkpoint()
        print(f"Full graph sync complete. {chunks_with_neighbors}/{len(chunks)} chunks have neighbors.")


def run_incremental_sync(nid: int) -> None:
    """Update a single Drupal node in the knowledge graph without a full rebuild.

    Designed to be triggered by a Drupal node-save hook via
    ``drush sage:sync-graph --nid=X``, which POSTs to the Flask service's
    ``/sync`` endpoint.

    Steps
    -----
    1. Delete all existing chunks for the node from Milvus using a
       ``source_nid`` filter, ensuring stale chunks from the old node body
       are removed even if the chunk count changes.
    2. Fetch the updated node body from Drupal's export endpoint.
    3. Chunk the text and extract entities (Pass 1 equivalent).
    4. Embed the new chunks via the Google embedding API (Pass 2 equivalent).
    5. Search the existing collection for neighbours and build typed edges
       (``_build_incremental_neighbors``).
    6. Insert the new chunks with their neighbour arrays into Milvus.

    Note:
        This function does not rebuild the IVF_SQ8 index after insertion. New
        chunks are findable via brute-force search until the next full Pass 2.5
        is run. For small incremental additions this recall difference is
        negligible.

    Args:
        nid: The Drupal node ID to update. Must correspond to a node that the
            export endpoint can return.
    """
    print(f"Starting incremental update for nid {nid}...")
    client    = get_client()
    dimension = _get_dimension()

    _ensure_collection(client, dimension)
    client.delete(COLLECTION_NAME, filter=f"source_nid == {nid}")

    nodes  = _fetch_nodes(nid)
    chunks = _build_chunks(nodes)

    if not chunks:
        print("No chunks for this node.")
        return

    if os.path.exists(PASS3A_FILE):
        with open(PASS3A_FILE, 'rb') as f:
            threshold = float(pickle.load(f)['threshold'])
        print(f"  Using corpus-calibrated edge threshold: {threshold:.4f}")
    else:
        threshold = 0.55
        print(f"  No full-sync threshold found — using fallback threshold: {threshold}")

    _extract_entities(chunks)
    _generate_vectors(chunks, dimension)
    _build_incremental_neighbors(client, chunks, dimension, threshold=threshold)

    for chunk in chunks:
        chunk['keywords'] = [str(kw)[:200] for kw in chunk.get('keywords', [])][:30]
        chunk['entities'] = [e[:200] for e in chunk.get('entities', [])][:150]

    _milvus_insert_with_retry(client, chunks)
    print(f"Incremental sync for nid {nid} complete.")


# ── Entry point ────────────────────────────────────────────────────────────────

if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(
        description="SAGE knowledge-graph pipeline. Runs a full rebuild by default.",
    )
    parser.add_argument(
        '--nid',
        type=int,
        required=False,
        help="Drupal node ID for an incremental single-node update.",
    )
    parser.add_argument(
        '--resume',
        action='store_true',
        help="Resume a previously interrupted full sync from the saved checkpoint.",
    )
    parser.add_argument(
        '--skip-index',
        action='store_true',
        help="Skip Pass 2.5 index rebuild and reload the existing collection. "
             "Use when resuming Pass 3 after a crash where the index is already valid.",
    )
    args = parser.parse_args()

    if args.nid:
        run_incremental_sync(args.nid)
    else:
        run_full_sync(resume=args.resume, skip_index=args.skip_index)
