# A Structure Aware Graph Expansion (SAGE) RAG setup Proof of Concept.

A Drupal 11 module and theme implementing a knowledge-graph-augmented AI chat interface for content discovery. This isn't a grab-and-go repo, but a POC for how a thematic corpus can leverage a knowledge graph to provide more relevant results, explain the context of the result set, and lower dependence on the inference model.

When it's working correctly, it should
- Reduce token use by front-loading the semantics. Flat retrieval into structured retrieval does the thinking for you.
- Provide friend-of-a-friend, multi-hop retrieval by comparing word overlap, edge types, reranking, chunks in the neighborhood, and weighted keywords that cater to both phrases and specific terms.
- Improve the quality of initial searches; provides context for the search results without relying on the inference model to generate one, limiting hallucinations.
- Adhere to a basic RAG approach. Results come from your data.
- Allow me to pretend that I understand what neurosymbolic AI means, even though this isn't it.

## What it is

The pipeline builds a semantic knowledge graph from Drupal node content using Google's text-embedding-004 model and Milvus as the vector store. Retrieval's handled by Drupal via a custom module wired to the native AI platform and Tooling API. The theme is a bare bones implementation of Tailwind to add some chatbot flare.

## What it isn't

This isn't GraphRAG. The structured data attempts to contextualize thematic data into structured relationships. There's no ontologies or triplets

This setup was meant for DDEV and not production. DDEV is wired to Python on your host machine via endpoint to bypass DDEVs container limitations. If it's on managed hosting it'll need a sidecar to populate the DB or run the pipeline via a cloud API.

This POC would require tweaking to align with your preferred models and services. YMMV depending on the instructions you provide the model.

This tool was built using a corpus of data scraped from the History of African-American civil rights category on wikipedia `https://en.wikipedia.org/wiki/Category:History_of_African-American_civil_rights`, roughly 10,000 content items. It hasn't been tested against any other corpus and the variables shouldn't be considered content-agnostic.

## What I'd like it to do

- Work with local and cloud-based models interchangeably.
- Provide more structured results via the Tools API.
- Allow for some configuration via the admin.

## Architecture overview

```
User query
    │
    ▼
AI Agent (Drupal ai_agents)
    │  calls
    ▼
SageGraphRetriever (tool plugin)
    ├── Google text-embedding-004   → query vector
    ├── Milvus ANN search           → seed nodes (hop-1)
    ├── Graph edge traversal        → expanded context (hop-2)
    └── Entity + keyword boosting   → ranked results
    │
    ▼
SageDiscoveryChatController → JSON response → sage-discovery.js → rendered UI
```

## The Pipeline

Right now, the pipeline runs locally before content is pushed to the Milvus DDEV addon:
- **`sage_pipeline.py`** — four-pass pipeline: NER (spaCy), embedding (Google), IVF_SQ8 indexing, two-stage percentile edge pruning
- **`sage_service.py`** — optional Flask service for triggering pipeline runs over HTTP

## The Chatbot and Retrieval

- **`SageGraphRetriever`** — The heart of the retrieval tool; performs two-hop graph traversal in Milvus, scores edges using cosine + entity Jaccard + keyword Jaccard, applies NER-based entity-type weighting (this is probably over-engineered but I'm liking the results I get)
- **`SageDiscoveryChatController`** — JSON parsing
- **`SageDrushCommands`** — See the breakdown of steps below. (Some of the logic should be moved out of Drush)
- **`SageCollectionSaver`** — AI Tool API plugin to build collections
- **`SageDiscoveryForm`** — the chat form with any scalar metadata as filters.

### The Theme

A boilerplate theme with some vanilla full page chatbot styles. Outside of `/sage/discover` you're not going to find anything pretty.

## Requirements

- Drupal 11
    - [AI module](https://www.drupal.org/project/ai)
    - [AI Agents](https://www.drupal.org/project/ai_agents)
- DDEV (for local development)
- Milvus 2.5+ (Using DDEV addons)
- Python 3.11+
- Google's Generative Language and Natural Language APIs
- A compatible key for keyword generation (In this case, I used Together.ai)
- Some sort of inference model within Drupal's AI settings. In my case, it's using Claude Opus 4.7 on Chat with Tools/Function Calling at `/admin/config/ai/settings`

## Setup

### 1. DDEV

```bash
cp .ddev/config.local.yaml.example .ddev/config.local.yaml
# Edit config.local.yaml and fill in your API keys
ddev start --profile=milvus
```

### 2. Install Drupal dependencies

```bash
ddev composer install
ddev drush site:install --existing-config
```

### 3. Enable the module

```bash
ddev drush en sage_content_discovery
```

```bash
cd web/themes/custom/sage_theme
npm install
npm run build
```

### 5. Run the pipeline and import into Drupal

Edit `python_pipeline/sage_pipeline.py` and set `DRUPAL_EXPORT_URL` to your site's export endpoint, then:

```bash
cd python_pipeline
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python -m spacy download en_core_web_lg
python sage_pipeline.py
```

```bash
ddev drush sage:import-signals /path/to/python_pipeline/sage_corpus_signals.json
```

## Environment variables

Set these in `.ddev/config.local.yaml` under `web_environment`:

| Variable | Description |
|---|---|
| `GOOGLE_API_KEY` | Google Cloud API key (embedding + NLP) |
| `INFERENCE_API_KEY` | Together AI (or compatible) key for LLM inference |


## Sources

I relied heavily on the approach of Prasham Titiya, Rohit Khoja, Tomer Wolfson, Vivek Gupta, Dan Roth in
[SAGE: Structure Aware Graph Expansion for Retrieval of Heterogeneous Data](https://arxiv.org/abs/2602.16964) https://arxiv.org/abs/2602.16964

Here's the repo that inspired the work— I cribbed the initial logic from their repo.
[sagejava](https://github.com/vishalmysore/sagejava) https://github.com/vishalmysore/sagejava

## References

If you're looking for more information on structured RAG retrieval, check out GraphRAG and the tenets of Neurosybolic AI.

If you're looking for a thought leader in AI, follow [Gary Marcus](https://x.com/garymarcus).

If you're looking to be disappointed, follow the Mets.