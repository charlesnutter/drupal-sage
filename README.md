# SAGE — Structure Aware Graph Expansion

A Drupal 11 module and theme implementing a knowledge-graph-augmented AI chat interface for content discovery.

## What it does

SAGE builds a semantic knowledge graph from Drupal node content using Google's text-embedding-004 model and Milvus as the vector store. At query time, a graph-traversal retriever expands a user's question across entity-typed edges (people, organizations, places, events, movements) to surface contextually connected content — not just the top-k nearest embeddings.

The result is surfaced through a chat interface powered by an AI agent that guides the user through a structured research flow.

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

## Components

### `web/modules/custom/sage_content_discovery/`
The core Drupal module:
- **`SageGraphRetriever`** — AI tool plugin; performs two-hop graph traversal in Milvus, scores edges using cosine + entity Jaccard + keyword Jaccard, applies NER-based entity-type weighting
- **`SageDiscoveryChatController`** — AJAX endpoint handling the chat session and structured JSON parsing
- **`SageDrushCommands`** — Drush commands for full and incremental sync, signal import
- **`SageCollectionSaver`** — AI tool plugin to persist result sets as Drupal node references
- **`SageDiscoveryForm`** — the chat UI form with age-range and entity-type filters

### `python_pipeline/`
Offline pipeline run locally before content is pushed to Milvus:
- **`sage_pipeline.py`** — four-pass pipeline: NER (spaCy), embedding (Google), IVF_SQ8 indexing, two-stage percentile edge pruning
- **`sage_service.py`** — optional Flask service for triggering pipeline runs over HTTP

### `web/themes/custom/sage_theme/`
Tailwind v4 + Vite theme providing the chatbot UI shell: centered empty state, FLIP-animated input transition, message bubbles, result cards, and suggestion chips.

## Requirements

- Drupal 11 with the [AI module](https://www.drupal.org/project/ai) and [AI Agents](https://www.drupal.org/project/ai_agents)
- DDEV (for local development)
- Milvus 2.5+ (provided via DDEV docker-compose addon)
- Python 3.11+ with the packages in `python_pipeline/requirements.txt`
- A Google Cloud project with the **Generative Language API** and **Natural Language API** enabled
- A Together AI (or compatible) API key for LLM inference

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

### 4. Build the theme

```bash
cd web/themes/custom/sage_theme
npm install
npm run build
```

### 5. Run the pipeline

Edit `python_pipeline/sage_pipeline.py` and set `DRUPAL_EXPORT_URL` to your site's export endpoint, then:

```bash
cd python_pipeline
python -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python -m spacy download en_core_web_lg
python sage_pipeline.py
```

### 6. Import pipeline artefacts into Drupal

```bash
ddev drush sage:import-signals /path/to/python_pipeline/sage_corpus_signals.json
```

## Environment variables

Set these in `.ddev/config.local.yaml` under `web_environment`:

| Variable | Description |
|---|---|
| `GOOGLE_API_KEY` | Google Cloud API key (embedding + NLP) |
| `INFERENCE_API_KEY` | Together AI (or compatible) key for LLM inference |

## License

GPL-2.0-or-later
