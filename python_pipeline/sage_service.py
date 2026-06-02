"""Flask HTTP service that exposes the SAGE pipeline over a local REST API.

Endpoints
---------
POST /sync
    Trigger a full graph build or incremental node update in the background.
    Returns 202 immediately; poll /status for completion. The ``skip_index``
    flag (Pass 2.5) is not exposed here; use the CLI directly when recovering
    from a crash where the IVF_SQ8 index is already valid.

GET /status
    Return the current sync state (idle | running | complete | error).

POST /ner
    Run spaCy NER on arbitrary text and return the detected entity type labels.
    Used by the Drupal retriever to classify query intent at runtime.

GET /health
    Liveness probe — returns {"status": "ok"} when the service is up.

Concurrency
-----------
A single ``threading.Lock`` prevents concurrent sync runs. Any second
``POST /sync`` while a run is active returns 429. The lock is always released
in the ``_run_sync`` finally block, even on exception.

Usage
-----
Development (DDEV)::

    python sage_service.py          # listens on 0.0.0.0:5001

Production (Cloud Run / gunicorn)::

    gunicorn -w 1 sage_service:app  # single worker to honour the lock
"""

import threading

from flask import Flask, jsonify, request

from sage_pipeline import extract_query_entity_types, run_full_sync, run_incremental_sync

# ── App setup ─────────────────────────────────────────────────────────────────

app   = Flask(__name__)
_lock = threading.Lock()

# ── Shared sync state ─────────────────────────────────────────────────────────
# Written by _run_sync in the background thread; read by /status.
# No additional locking is needed: Python's GIL makes individual dict-item
# assignments atomic, and the fields are only mutated while _lock is held or
# from the finally block where _lock is about to be released.
_state = {
    "status":  "idle",  # idle | running | complete | error
    "message": "",
}


# ── Background runner ─────────────────────────────────────────────────────────

def _run_sync(nid: int | None = None, resume: bool = False) -> None:
    """Execute a pipeline sync and update the shared state dict.

    Runs in a daemon thread spawned by the ``/sync`` endpoint. Always releases
    ``_lock`` in the finally block so the service can accept a new sync after
    the current one finishes or fails.

    Args:
        nid: Drupal node ID to re-index incrementally. When ``None`` a full
            graph rebuild is performed instead.
        resume: When ``True`` and ``nid`` is ``None``, resume a previously
            interrupted full sync from the last saved checkpoint rather than
            starting over.
    """
    try:
        if nid:
            run_incremental_sync(int(nid))
            _state["message"] = f"Incremental sync for nid {nid} complete."
        else:
            run_full_sync(resume=resume)
            _state["message"] = "Full graph sync complete."
        _state["status"] = "complete"
    except Exception as e:
        _state["status"]  = "error"
        _state["message"] = str(e)
    finally:
        _lock.release()


# ── Endpoints ─────────────────────────────────────────────────────────────────

@app.route("/sync", methods=["POST"])
def sync():
    """Start a background graph sync.

    Accepts an optional JSON body::

        {"nid": 42}            # incremental re-index for node 42
        {"resume": true}       # resume an interrupted full sync
        {}                     # fresh full rebuild (default)

    ``resume`` applies only to full syncs. Sending both ``nid`` and ``resume``
    returns 400 — ``resume`` has no meaning for incremental updates.

    Returns 429 if a sync is already running. Otherwise spawns a daemon thread,
    updates ``_state["status"]`` to ``"running"``, and returns 202 immediately
    so the caller is not blocked.

    Returns:
        A JSON response with ``status`` and ``message`` fields, and one of:

        * 202 — sync started in background.
        * 400 — both ``nid`` and ``resume`` were provided.
        * 429 — a sync is already in progress.
    """
    if not _lock.acquire(blocking=False):
        return jsonify({"error": "A sync is already running"}), 429

    data = request.json if request.is_json else {}
    if not isinstance(data, dict):
        data = {}
    nid    = data.get("nid")
    resume = bool(data.get("resume", False))

    if nid and resume:
        _lock.release()
        return jsonify({"error": "'resume' is only valid for full syncs; remove 'nid' or 'resume'."}), 400

    _state["status"]  = "running"
    _state["message"] = ""

    thread = threading.Thread(target=_run_sync, args=(nid, resume), daemon=True)
    thread.start()

    return jsonify({"status": "running", "message": "Sync started in background."}), 202


@app.route("/status", methods=["GET"])
def status():
    """Return the current sync status.

    Reads the shared ``_state`` dict that ``_run_sync`` updates as it runs.

    Returns:
        A JSON response with two fields:

        * ``status`` — one of ``"idle"``, ``"running"``, ``"complete"``,
          or ``"error"``.
        * ``message`` — human-readable detail string (empty while running,
          populated on completion or error).

        HTTP 200 in all cases.
    """
    return jsonify(_state), 200


@app.route("/ner", methods=["POST"])
def ner():
    """Run spaCy NER on a text string and return the detected entity type labels.

    Used by the Drupal ``SageGraphRetriever`` to classify query intent at
    retrieval time so that edge-type weights can be applied without maintaining
    hardcoded entity lists. The retriever falls back to neutral weights if the
    service is slow or unavailable.

    Expects a JSON body::

        {"text": "Who was Fannie Lou Hamer?"}

    Returns:
        A JSON response with a single ``entity_types`` key containing a list of
        unique spaCy entity label strings detected in the input text (e.g.
        ``["PERSON"]``). Returns an empty list when ``text`` is absent or blank.
        HTTP 200 in all cases.

    Example::

        POST /ner  {"text": "March on Washington"}
        → {"entity_types": ["EVENT", "GPE"]}
    """
    data = request.json if request.is_json else {}
    text = (data.get("text") or "").strip()
    if not text:
        return jsonify({"entity_types": []}), 200
    entity_types = extract_query_entity_types(text)
    return jsonify({"entity_types": entity_types}), 200


@app.route("/health", methods=["GET"])
def health():
    """Liveness probe for container orchestrators and load balancers.

    Returns:
        ``{"status": "ok"}`` with HTTP 200 when the service is reachable.
    """
    return jsonify({"status": "ok"}), 200


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5001)
