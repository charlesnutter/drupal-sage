<?php

namespace Drupal\sage_content_discovery\Commands;

use Drush\Commands\DrushCommands;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request as GuzzleRequest;

/**
 * A Drush command file for SAGE Graph operations.
 */
class SageDrushCommands extends DrushCommands {

  const SAGE_SERVICE_BASE = 'http://host.docker.internal:5001';
  const POLL_INTERVAL_SECONDS = 10;

  /**
   * Triggers the SAGE Python pipeline processor.
   *
   * @command sage:sync-graph
   * @option nid Provide a specific Drupal Node ID to run an incremental item synchronization.
   * @option resume Resume a previously interrupted full sync from its last checkpoint.
   * @usage drush sage:sync-graph
   * @usage drush sage:sync-graph --nid=42
   * @usage drush sage:sync-graph --resume
   */
  public function syncGraph(array $options = ['nid' => NULL, 'resume' => FALSE]) {
    $nid    = $options['nid'];
    $resume = !empty($options['resume']);

    if (!empty($nid)) {
      $this->output()->writeln(dt('Starting incremental PHP sync for Node ID: @nid...', ['@nid' => $nid]));
      $this->runIncrementalPhpSync((int) $nid);
      return;
    }

    $client = \Drupal::httpClient();

    if ($resume) {
      $this->output()->writeln(dt('Resuming SAGE graph sync from last checkpoint...'));
    }
    else {
      $this->output()->writeln(dt('Starting full SAGE graph rebuild...'));
    }

    // Fire off the sync — service returns 202 immediately.
    // Always send an object (not an empty array) so Flask sees a dict, not a list.
    $payload = ['resume' => $resume];
    if (!empty($nid)) {
      $payload['nid'] = (int) $nid;
    }

    try {
      $response = $client->post(self::SAGE_SERVICE_BASE . '/sync', [
        'json'    => $payload,
        'timeout' => 15,
      ]);

      $body   = json_decode($response->getBody(), TRUE);
      $status = $body['status'] ?? '';
      if ($status === 'error' || !empty($body['error'])) {
        $this->logger()->error(dt('SAGE service rejected the request: @msg', ['@msg' => $body['error'] ?? $body['message'] ?? 'unknown']));
        return;
      }
      if ($status !== 'running') {
        $this->logger()->error(dt('Unexpected response from SAGE service: @msg', ['@msg' => $body['message'] ?? 'unknown']));
        return;
      }

      $this->output()->writeln(dt('Sync running in background. Polling for completion every @s seconds...', [
        '@s' => self::POLL_INTERVAL_SECONDS,
      ]));
    }
    catch (RequestException $e) {
      $this->logger()->error(dt('Could not reach SAGE service at @url. Is sage_service.py running on your host?', [
        '@url' => self::SAGE_SERVICE_BASE,
      ]));
      return;
    }

    // Poll /status until done.
    while (TRUE) {
      sleep(self::POLL_INTERVAL_SECONDS);

      try {
        $status_response = $client->get(self::SAGE_SERVICE_BASE . '/status', ['timeout' => 10]);
        $state = json_decode($status_response->getBody(), TRUE);
        $status = $state['status'] ?? 'unknown';

        if ($status === 'complete') {
          $this->logger()->success(dt('SAGE sync complete: @msg', ['@msg' => $state['message'] ?? '']));
          return;
        }

        if ($status === 'error') {
          $this->logger()->error(dt('SAGE sync failed: @msg', ['@msg' => $state['message'] ?? 'unknown error']));
          return;
        }

        // Still running — print a heartbeat so the terminal doesn't look frozen.
        $this->output()->writeln(dt('  ... still running (@status)', ['@status' => $status]));
      }
      catch (RequestException $e) {
        $this->logger()->warning(dt('Status poll failed (will retry): @msg', ['@msg' => $e->getMessage()]));
      }
    }
  }

  /**
   * Generates keywords for nodes whose keywords field is empty.
   *
   * Queries for nodes of the given type(s) where the target field has no
   * value, then resaves them in batches so the configured AI Automator
   * processes and populates the field. Nodes that already have keywords are
   * excluded from the query and never touched.
   *
   * @command sage:generate-keywords
   * @option type Machine name of the content type(s) to process. Separate multiple types with a comma.
   * @option field Machine name of the keywords field to check. Defaults to field_keywords.
   * @option batch-size Number of nodes to load and process per iteration. Defaults to 200.
   * @option max-batches Maximum number of batches to run before stopping. Omit to process all matching nodes.
   * @usage drush sage:generate-keywords --type=resource --field=field_keywords
   * @usage drush sage:generate-keywords --type=resource,article --field=field_focus_area --batch-size=100
   * @usage drush sage:generate-keywords --type=resource --batch-size=5 --max-batches=5
   */
  public function generateKeywords(array $options = [
    'type'        => NULL,
    'field'       => 'field_keywords_plain',
    'batch-size'  => 200,
    'max-batches' => NULL,
  ]): void {
    $types       = $options['type'];
    $field       = $options['field'];
    $batch_size  = max(1, (int) $options['batch-size']);
    $max_batches = $options['max-batches'] !== NULL ? max(1, (int) $options['max-batches']) : NULL;

    if (!$types) {
      $this->logger()->error('You must specify at least one content type with --type.');
      return;
    }

    $type_list = array_map('trim', explode(',', $types));
    $nids      = $this->queryNidsWithEmptyField($type_list, $field);

    if (empty($nids)) {
      $this->logger()->success(dt('No nodes found with an empty @field field. Nothing to do.', [
        '@field' => $field,
      ]));
      return;
    }

    $total            = count($nids);
    $chunks           = array_chunk(array_values($nids), $batch_size);
    $available_batches = count($chunks);

    if ($max_batches !== NULL) {
      $chunks = array_slice($chunks, 0, $max_batches);
    }

    $run_batches = count($chunks);

    $this->output()->writeln(dt(
      'Found @total node(s) with empty @field. Running @run of @available batch(es) (up to @size nodes each).',
      [
        '@total'     => $total,
        '@field'     => $field,
        '@run'       => $run_batches,
        '@available' => $available_batches,
        '@size'      => $batch_size,
      ]
    ));

    $processed = 0;
    $errors    = 0;

    foreach ($chunks as $batch_index => $chunk) {
      $batch_num = $batch_index + 1;
      $this->output()->writeln(dt('  Batch @num / @total (@count nodes)...', [
        '@num'   => $batch_num,
        '@total' => count($chunks),
        '@count' => count($chunk),
      ]));

      /** @var \Drupal\node\Entity\Node[] $nodes */
      $nodes = \Drupal::entityTypeManager()
        ->getStorage('node')
        ->loadMultiple($chunk);

      foreach ($nodes as $node) {
        // Guard against a race condition where another process populated the
        // field between the initial query and now.
        if (!$node->get($field)->isEmpty()) {
          continue;
        }

        try {
          // Resaving triggers hook_entity_presave, which is where AI Automator
          // runs its field-level automations. The automator must be configured
          // with an "on save" trigger for this approach to work. If the
          // automator uses a "manual" trigger, swap this for a direct call to
          // the ai_automators runner service instead.
          $node->setNewRevision(FALSE);
          $node->save();
          $processed++;
          $this->output()->writeln(dt('    [nid:@nid] @title', [
            '@nid'   => $node->id(),
            '@title' => $node->label(),
          ]));
        }
        catch (\Exception $e) {
          $errors++;
          $this->logger()->warning(dt('  Node @nid failed: @msg', [
            '@nid' => $node->id(),
            '@msg' => $e->getMessage(),
          ]));
        }
      }

      // Release the entity cache between batches to keep memory flat.
      \Drupal::entityTypeManager()->getStorage('node')->resetCache($chunk);

      $this->output()->writeln(dt('  Batch @num complete. Running totals: @ok processed, @err error(s).', [
        '@num' => $batch_num,
        '@ok'  => $processed,
        '@err' => $errors,
      ]));
    }

    if ($errors === 0) {
      $this->logger()->success(dt('Done. @ok node(s) processed.', ['@ok' => $processed]));
    }
    else {
      $this->logger()->warning(dt('Done. @ok processed, @err error(s). Review warnings above.', [
        '@ok'  => $processed,
        '@err' => $errors,
      ]));
    }
  }

  /**
   * Seeds an entity reference field with evenly distributed taxonomy terms.
   *
   * Loads all terms from the given vocabulary, shuffles the target nodes, then
   * assigns terms via modulo so each term receives an equal (±1) share of nodes
   * regardless of total count. Existing field values are overwritten.
   *
   * @command sage:seed-age-range
   * @option type Content type machine name to target. Defaults to topic.
   * @option field Machine name of the entity reference field. Defaults to field_age.
   * @option vocabulary Taxonomy vocabulary machine name to load terms from. Defaults to age.
   * @option batch-size Number of nodes to load per iteration. Defaults to 200.
   * @usage drush sage:seed-age-range
   * @usage drush sage:seed-age-range --type=topic --field=field_age --vocabulary=age
   */
  public function seedAgeRange(array $options = [
    'type'       => 'topic',
    'field'      => 'field_age',
    'vocabulary' => 'age',
    'batch-size' => 200,
  ]): void {
    $type       = $options['type']       ?? 'topic';
    $field      = $options['field']      ?? 'field_age';
    $vocabulary = $options['vocabulary'] ?? 'age';
    $batch_size = max(1, (int) ($options['batch-size'] ?? 200));

    // Load all terms from the vocabulary.
    $tids = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', $vocabulary)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($tids)) {
      $this->logger()->error(dt('No terms found in vocabulary "@vocab".', ['@vocab' => $vocabulary]));
      return;
    }

    $tids         = array_values($tids);
    $term_count   = count($tids);
    $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
    $terms        = $term_storage->loadMultiple($tids);

    $this->output()->writeln(dt('Vocabulary "@vocab" — @count term(s): @labels', [
      '@vocab'  => $vocabulary,
      '@count'  => $term_count,
      '@labels' => implode(', ', array_map(fn($t) => $t->label(), $terms)),
    ]));

    // Load all target node IDs and shuffle for even distribution.
    $nids = \Drupal::entityQuery('node')
      ->condition('type', $type)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($nids)) {
      $this->logger()->error(dt('No nodes found of type "@type".', ['@type' => $type]));
      return;
    }

    $nids  = array_values($nids);
    $total = count($nids);
    shuffle($nids);

    $this->output()->writeln(dt(
      'Distributing @total "@type" node(s) across @terms term(s) in batches of @size.',
      ['@total' => $total, '@type' => $type, '@terms' => $term_count, '@size' => $batch_size],
    ));

    $chunks      = array_chunk($nids, $batch_size);
    $index       = 0;
    $processed   = 0;
    $errors      = 0;
    $distribution = array_fill_keys($tids, 0);

    foreach ($chunks as $batch_index => $chunk) {
      $batch_num = $batch_index + 1;
      $this->output()->writeln(dt('  Batch @num / @total (@count nodes)...', [
        '@num'   => $batch_num,
        '@total' => count($chunks),
        '@count' => count($chunk),
      ]));

      $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($chunk);

      foreach ($nodes as $node) {
        // Modulo over the shuffled list guarantees each term is assigned an
        // equal (±1) number of nodes across the full set.
        $tid = $tids[$index % $term_count];
        $index++;

        try {
          $node->set($field, ['target_id' => $tid]);
          $node->setNewRevision(FALSE);
          $node->save();
          $distribution[$tid]++;
          $processed++;
          $this->output()->writeln(dt('    [nid:@nid] @title → @term', [
            '@nid'   => $node->id(),
            '@title' => $node->label(),
            '@term'  => $terms[$tid]->label(),
          ]));
        }
        catch (\Exception $e) {
          $errors++;
          $this->logger()->warning(dt('    Node @nid failed: @msg', [
            '@nid' => $node->id(),
            '@msg' => $e->getMessage(),
          ]));
        }
      }

      \Drupal::entityTypeManager()->getStorage('node')->resetCache($chunk);
    }

    // Print per-term distribution so the spread can be verified at a glance.
    $this->output()->writeln('');
    $this->output()->writeln('Distribution:');
    foreach ($distribution as $tid => $count) {
      $this->output()->writeln(dt('  @term: @count node(s)', [
        '@term'  => $terms[$tid]->label(),
        '@count' => $count,
      ]));
    }

    if ($errors === 0) {
      $this->logger()->success(dt('Done. @ok node(s) updated.', ['@ok' => $processed]));
    }
    else {
      $this->logger()->warning(dt('Done. @ok updated, @err error(s). Review warnings above.', [
        '@ok'  => $processed,
        '@err' => $errors,
      ]));
    }
  }

  /**
   * Generates keywords for nodes via an OpenAI-compatible API with concurrent requests.
   *
   * Designed for fast inference providers (Cerebras, Fireworks AI, Together AI,
   * Groq, etc.). Calls the chat completions endpoint directly, firing up to
   * --concurrency requests simultaneously per batch via Guzzle Pool. Nodes
   * whose keywords field already has a value are excluded from the query.
   * The API key is read from --api-key or the INFERENCE_API_KEY environment variable.
   *
   * @command sage:generate-keywords-api
   * @option type Content type machine name(s) to process, comma-separated.
   * @option field Machine name of the plain-text keywords field. Defaults to field_keywords_plain.
   * @option body-field Machine name of the source text field. Defaults to body.
   * @option endpoint OpenAI-compatible chat completions base URL. Defaults to Cerebras.
   * @option model Model ID for the chosen provider. Defaults to llama3.1-8b (Cerebras).
   * @option concurrency Number of simultaneous API requests per batch. Defaults to 10.
   * @option batch-size Number of nodes to load per iteration. Defaults to 50.
   * @option max-batches Maximum number of batches to run before stopping. Omit to process all matching nodes.
   * @option api-key API key. Falls back to INFERENCE_API_KEY environment variable.
   * @usage drush sage:generate-keywords-api --type=topic
   * @usage drush sage:generate-keywords-api --type=topic --batch-size=5 --max-batches=1
   * @usage drush sage:generate-keywords-api --type=topic --endpoint=https://api.together.xyz/v1 --model=meta-llama/Meta-Llama-3-8B-Instruct-Lite
   */
  public function generateKeywordsApi(array $options = [
    'type'        => NULL,
    'field'       => 'field_keywords_plain',
    'body-field'  => 'body',
    'endpoint'    => 'https://api.cerebras.ai/v1',
    'model'       => 'llama3.1-8b',
    'concurrency' => 10,
    'batch-size'  => 50,
    'max-batches' => NULL,
    'api-key'     => NULL,
  ]): void {
    $api_key     = $options['api-key'] ?: getenv('INFERENCE_API_KEY');
    $types       = $options['type'];
    $field       = $options['field'];
    $body_field  = $options['body-field'];
    $endpoint    = rtrim($options['endpoint'], '/');
    $model       = $options['model'];
    $concurrency = max(1, (int) $options['concurrency']);
    $batch_size  = max(1, (int) $options['batch-size']);
    $max_batches = $options['max-batches'] !== NULL ? max(1, (int) $options['max-batches']) : NULL;

    if (!$api_key) {
      $this->logger()->error('No API key provided. Use --api-key or set the INFERENCE_API_KEY environment variable.');
      return;
    }

    if (!$types) {
      $this->logger()->error('You must specify at least one content type with --type.');
      return;
    }

    $type_list = array_map('trim', explode(',', $types));

    $nids = $this->queryNidsWithEmptyField($type_list, $field);

    if (empty($nids)) {
      $this->logger()->success(dt('No nodes found with an empty @field field. Nothing to do.', [
        '@field' => $field,
      ]));
      return;
    }

    $total             = count($nids);
    $chunks            = array_chunk($nids, $batch_size);
    $available_batches = count($chunks);

    if ($max_batches !== NULL) {
      $chunks = array_slice($chunks, 0, $max_batches);
    }

    $this->output()->writeln(dt(
      'Found @total node(s). Processing @run of @available batch(es) of @size with concurrency @con using @model via @endpoint.',
      [
        '@total'    => $total,
        '@run'      => count($chunks),
        '@available' => $available_batches,
        '@size'     => $batch_size,
        '@con'      => $concurrency,
        '@model'    => $model,
        '@endpoint' => $endpoint,
      ]
    ));

    $prompt_template = <<<'PROMPT'
You are a keyword extraction assistant. Your task is to read the provided text and return a list of 5 to 8 keywords or short phrases that
best represent the core topics, subjects, and themes of the content.

Rules:
- Each keyword should be 1 to 3 words.
- Prefer specific terms over generic ones. "Voting Rights Act" is better than "legislation."
- Capture named subjects (people, organizations, events, places) only if they are central to the text, not merely mentioned.
- Do not repeat concepts. If two keywords mean the same thing, keep the more specific one.
- Do not include the word "keywords" or any meta-commentary in your output.
- Don't include dates as keywords
- Don't include US States as keywords
- Limit the number of keywords to words or phrases that meet the requirements. Don't always use 8 if the size of the text dictates those keywords will not meet the requirements.

Text:
{{ context }}
PROMPT;

    $client     = new Client(['timeout' => 30]);
    $node_store = \Drupal::entityTypeManager()->getStorage('node');
    $processed  = 0;
    $errors     = 0;

    foreach ($chunks as $batch_index => $chunk) {
      $batch_num   = $batch_index + 1;
      $batch_nodes = array_values($node_store->loadMultiple($chunk));

      $this->output()->writeln(dt('  Batch @num / @total (@count nodes)...', [
        '@num'   => $batch_num,
        '@total' => count($chunks),
        '@count' => count($batch_nodes),
      ]));

      // Results are keyed by the node's position in $batch_nodes so fulfilled
      // and rejected callbacks can look up the corresponding node by index.
      $results = [];

      $requests = function () use ($batch_nodes, $api_key, $model, $prompt_template, $body_field, $endpoint) {
        foreach ($batch_nodes as $node) {
          $body_text = strip_tags($node->get($body_field)->value ?? '');
          $prompt    = str_replace('{{ context }}', $body_text, $prompt_template);

          yield new GuzzleRequest(
            'POST',
            $endpoint . '/chat/completions',
            [
              'Authorization' => 'Bearer ' . $api_key,
              'Content-Type'  => 'application/json',
            ],
            json_encode([
              'model'       => $model,
              'messages'    => [['role' => 'user', 'content' => $prompt]],
              'temperature' => 0,
              'max_tokens'  => 150,
            ]),
          );
        }
      };

      $pool = new Pool($client, $requests(), [
        'concurrency' => $concurrency,
        'fulfilled'   => function ($response, $index) use ($batch_nodes, $field, &$results, &$processed) {
          $node    = $batch_nodes[$index];
          $data    = json_decode($response->getBody()->getContents(), TRUE);
          $raw     = $data['choices'][0]['message']['content'] ?? '';
          $keywords = $this->parseKeywordLines($raw);

          if (empty($keywords)) {
            $results[$index] = ['status' => 'empty', 'node' => $node];
            return;
          }

          try {
            $node->set($field, implode(', ', $keywords));
            $node->setNewRevision(FALSE);
            $node->save();
            $processed++;
            $results[$index] = ['status' => 'ok', 'node' => $node, 'keywords' => $keywords];
          }
          catch (\Exception $e) {
            $results[$index] = ['status' => 'save_error', 'node' => $node, 'message' => $e->getMessage()];
          }
        },
        'rejected' => function ($reason, $index) use ($batch_nodes, &$results) {
          $results[$index] = [
            'status'  => 'api_error',
            'node'    => $batch_nodes[$index],
            'message' => $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason,
          ];
        },
      ]);

      $pool->promise()->wait();

      // Print results in node order so output is readable regardless of
      // which responses arrived first.
      ksort($results);
      foreach ($results as $result) {
        $node = $result['node'];
        match ($result['status']) {
          'ok' => $this->output()->writeln(dt('    [nid:@nid] @title → @kw', [
            '@nid'   => $node->id(),
            '@title' => $node->label(),
            '@kw'    => implode(', ', $result['keywords']),
          ])),
          'empty' => $this->logger()->warning(dt('    [nid:@nid] @title — model returned no keywords', [
            '@nid'   => $node->id(),
            '@title' => $node->label(),
          ])),
          default => $this->logger()->warning(dt('    [nid:@nid] @title — @msg', [
            '@nid'   => $node->id(),
            '@title' => $node->label(),
            '@msg'   => $result['message'] ?? $result['status'],
          ])),
        };

        if ($result['status'] !== 'ok') {
          $errors++;
        }
      }

      $node_store->resetCache($chunk);

      $this->output()->writeln(dt('  Batch @num complete. Running totals: @ok processed, @err error(s).', [
        '@num' => $batch_num,
        '@ok'  => $processed,
        '@err' => $errors,
      ]));
    }

    if ($errors === 0) {
      $this->logger()->success(dt('Done. @ok node(s) processed.', ['@ok' => $processed]));
    }
    else {
      $this->logger()->warning(dt('Done. @ok processed, @err error(s). Review warnings above.', [
        '@ok'  => $processed,
        '@err' => $errors,
      ]));
    }
  }

  /**
   * Imports corpus entity signals into the Drupal key-value store.
   *
   * Reads sage_corpus_signals.json (written by the Python pipeline after Pass 1)
   * and stores the entity-name lists under key 'corpus_signals' in the 'sage'
   * key-value collection. The PHP retriever reads this at query time to boost
   * entity types without calling a Python sidecar.
   *
   * @command sage:import-signals
   * @option path Absolute path to sage_corpus_signals.json. Defaults to ../python_pipeline/sage_corpus_signals.json relative to DRUPAL_ROOT.
   * @usage drush sage:import-signals
   * @usage drush sage:import-signals --path=/var/app/sage_corpus_signals.json
   */
  public function importSignals(array $options = ['path' => NULL]): void {
    $path = $options['path'] ?? NULL;

    if (empty($path)) {
      $path = DRUPAL_ROOT . '/../python_pipeline/sage_corpus_signals.json';
    }

    if (!file_exists($path)) {
      $this->io()->error(dt('File not found: @path', ['@path' => $path]));
      return;
    }

    $raw     = file_get_contents($path);
    $signals = json_decode($raw, TRUE);

    if (!is_array($signals)) {
      $this->io()->error(dt('Failed to parse JSON from @path', ['@path' => $path]));
      return;
    }

    \Drupal::keyValue('sage')->set('corpus_signals', $signals);

    $total = array_sum(array_map('count', $signals));
    $this->output()->writeln(dt(
      'Corpus signals imported: @total entries across @types entity types.',
      ['@total' => $total, '@types' => count($signals)],
    ));

    // Also import the corpus-calibrated edge threshold if the config file exists
    // alongside the signals file (written by the Python pipeline after Pass 3a).
    $config_path = dirname($path) . '/sage_graph_config.json';
    if (file_exists($config_path)) {
      $config = json_decode(file_get_contents($config_path), TRUE);
      if (isset($config['edge_threshold']) && is_numeric($config['edge_threshold'])) {
        \Drupal::keyValue('sage')->set('edge_threshold', (float) $config['edge_threshold']);
        $this->output()->writeln(dt(
          'Edge threshold imported: @t (from @p)',
          ['@t' => $config['edge_threshold'], '@p' => $config_path],
        ));
      }
    }
  }

  /**
   * Performs a PHP-native incremental sync for a single Drupal node.
   *
   * Chunks the node body, extracts entities via Google Cloud Natural Language
   * API (normalised to spaCy-compatible type labels), embeds via the Google
   * embedding API, searches Milvus for typed graph edges, and upserts. Runs
   * entirely within Drupal — no Python sidecar required.
   */
  private function runIncrementalPhpSync(int $nid): void {
    $client     = \Drupal::httpClient();
    $google_key = \Drupal::service('key.repository')->getKey('google_embedding_key')?->getKeyValue();
    $threshold  = (float) (\Drupal::keyValue('sage')->get('edge_threshold', 0.55));

    if (empty($google_key)) {
      $this->io()->error('Google API key not configured (key: google_embedding_key).');
      return;
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node || !$node->isPublished()) {
      $this->io()->error(dt('Node @nid not found or not published.', ['@nid' => $nid]));
      return;
    }

    $body = strip_tags($node->get('body')->value ?? '');
    if (empty(trim($body))) {
      $this->io()->error(dt('Node @nid has no body text to index.', ['@nid' => $nid]));
      return;
    }

    $age_range = $node->hasField('field_age') ? ($node->get('field_age')->value ?? '') : '';
    $keywords  = [];
    if ($node->hasField('field_keywords_plain')) {
      foreach ($node->get('field_keywords_plain') as $item) {
        $keywords[] = substr((string) $item->value, 0, 200);
      }
      $keywords = array_slice($keywords, 0, 30);
    }

    $this->milvusDeleteNode($nid, $client);

    $chunks = $this->chunkNodeText($body, $nid, $age_range, $keywords);
    if (empty($chunks)) {
      $this->io()->warning(dt('No chunks generated for node @nid.', ['@nid' => $nid]));
      return;
    }
    $this->output()->writeln(dt('  @n chunks generated. Threshold: @t', ['@n' => count($chunks), '@t' => $threshold]));

    $this->extractEntitiesGoogleNlp($chunks, $google_key, $client);
    $this->embedChunks($chunks, $google_key, $client);

    $chunks = array_values(array_filter($chunks, fn($c) => !empty($c['vector'])));
    if (empty($chunks)) {
      $this->io()->error('All chunk embeddings failed — aborting insert.');
      return;
    }

    $this->buildIncrementalNeighbors($chunks, $threshold, $client);

    foreach ($chunks as &$chunk) {
      $chunk['keywords'] = array_slice(array_map(fn($kw) => substr($kw, 0, 200), $chunk['keywords']), 0, 30);
      $chunk['entities'] = array_slice(array_map(fn($e) => substr($e, 0, 200), $chunk['entities']), 0, 150);
    }
    unset($chunk);

    $this->milvusInsertChunks($chunks, $client);
    $this->output()->writeln(dt('  Incremental PHP sync complete for nid @nid (@n chunks inserted).', [
      '@nid' => $nid,
      '@n'   => count($chunks),
    ]));
  }

  private function chunkNodeText(string $body, int $nid, string $age_range, array $keywords): array {
    $words     = preg_split('/\s+/', trim($body), -1, PREG_SPLIT_NO_EMPTY);
    $total     = count($words);
    $chunks    = [];
    $chunk_idx = 0;

    for ($i = 0; $i < $total; $i += 100) {
      $text = implode(' ', array_slice($words, $i, 120));
      if (strlen(trim($text)) > 10) {
        $chunks[] = [
          'chunk_id'   => "nid_{$nid}_c_{$chunk_idx}",
          'source_nid' => $nid,
          'text'       => $text,
          'age_range'  => $age_range,
          'keywords'   => $keywords,
          'entities'   => [],
          'vector'     => [],
          'neighbors'  => [],
        ];
        $chunk_idx++;
      }
    }
    return $chunks;
  }

  private function extractEntitiesGoogleNlp(array &$chunks, string $api_key, object $client): void {
    // Maps Google NLP types to the spaCy-compatible labels stored during full rebuild.
    // NORP has no Google NLP equivalent and is intentionally omitted.
    $type_map = [
      'PERSON'       => 'PERSON',
      'ORGANIZATION' => 'ORG',
      'LOCATION'     => 'GPE',
      'EVENT'        => 'EVENT',
    ];

    foreach ($chunks as &$chunk) {
      try {
        $response = $client->request('POST',
          'https://language.googleapis.com/v1/documents:analyzeEntities?key=' . $api_key, [
          'json'    => [
            'document'     => ['type' => 'PLAIN_TEXT', 'content' => $chunk['text']],
            'encodingType' => 'UTF8',
          ],
          'timeout' => 10,
        ]);
        $data     = json_decode($response->getBody()->getContents(), TRUE);
        $entities = [];
        foreach ($data['entities'] ?? [] as $entity) {
          $mapped_type = $type_map[$entity['type'] ?? ''] ?? NULL;
          if ($mapped_type === NULL) {
            continue;
          }
          $entities[] = $mapped_type . ':' . $entity['name'];
        }
        $chunk['entities'] = array_values(array_unique(array_slice($entities, 0, 150)));
      }
      catch (\Exception) {
        // Leaves entities as [] — edges will be purely semantic for this chunk.
      }
    }
    unset($chunk);
  }

  private function embedChunks(array &$chunks, string $api_key, object $client): void {
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent?key=' . $api_key;
    foreach ($chunks as &$chunk) {
      try {
        $response        = $client->request('POST', $url, [
          'json'    => [
            'model'    => 'models/gemini-embedding-2',
            'content'  => ['parts' => [['text' => $chunk['text']]]],
            'taskType' => 'RETRIEVAL_DOCUMENT',
          ],
          'timeout' => 15,
        ]);
        $chunk['vector'] = json_decode($response->getBody()->getContents(), TRUE)['embedding']['values'] ?? [];
      }
      catch (\Exception) {
        $chunk['vector'] = [];
      }
    }
    unset($chunk);
  }

  private function buildIncrementalNeighbors(array &$chunks, float $threshold, object $client): void {
    foreach ($chunks as &$chunk) {
      try {
        $response = $client->request('POST', 'http://milvus:19530/v1/vector/search', [
          'json'    => [
            'collectionName' => 'sage_knowledge_graph',
            'vector'         => $chunk['vector'],
            'limit'          => 11,
            'outputFields'   => ['chunk_id', 'entities', 'keywords'],
          ],
          'timeout' => 15,
        ]);
        $hits      = json_decode($response->getBody()->getContents(), TRUE)['data'] ?? [];
        $neighbors = [];

        foreach ($hits as $hit) {
          if ($hit['chunk_id'] === $chunk['chunk_id']) {
            continue;
          }
          $hit_entities = $this->sageExtractArrayField($hit['entities'] ?? []);
          $hit_keywords = $this->sageExtractArrayField($hit['keywords'] ?? []);
          $score        = $this->sageEdgeScore(
            (float) ($hit['distance'] ?? 0.0),
            $chunk['entities'],
            $hit_entities,
            $chunk['keywords'],
            $hit_keywords,
          );
          if ($score >= $threshold) {
            $edge_type   = $this->sageClassifyEdgeType($chunk['entities'], $hit_entities);
            $neighbors[] = $edge_type . ':' . $hit['chunk_id'];
          }
        }
        $chunk['neighbors'] = $neighbors;
      }
      catch (\Exception) {
        $chunk['neighbors'] = [];
      }
    }
    unset($chunk);
  }

  private function milvusDeleteNode(int $nid, object $client): void {
    try {
      $client->request('POST', 'http://milvus:19530/v1/vector/delete', [
        'json'    => [
          'collectionName' => 'sage_knowledge_graph',
          'filter'         => "source_nid == $nid",
        ],
        'timeout' => 15,
      ]);
    }
    catch (\Exception $e) {
      $this->logger()->warning(dt('Milvus delete failed for nid @nid: @msg', [
        '@nid' => $nid,
        '@msg' => $e->getMessage(),
      ]));
    }
  }

  private function milvusInsertChunks(array $chunks, object $client): void {
    $data = array_map(fn($c) => [
      'chunk_id'   => $c['chunk_id'],
      'source_nid' => $c['source_nid'],
      'text'       => $c['text'],
      'age_range'  => $c['age_range'],
      'keywords'   => $c['keywords'],
      'entities'   => $c['entities'],
      'vector'     => $c['vector'],
      'neighbors'  => $c['neighbors'],
    ], $chunks);

    try {
      $client->request('POST', 'http://milvus:19530/v1/vector/insert', [
        'json'    => ['collectionName' => 'sage_knowledge_graph', 'data' => $data],
        'timeout' => 30,
      ]);
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Milvus insert failed: @msg', ['@msg' => $e->getMessage()]));
    }
  }

  private function sageEdgeScore(float $cosine, array $entities_a, array $entities_b, array $keywords_a, array $keywords_b): float {
    return (0.60 * $cosine)
      + (0.25 * $this->sageJaccard($entities_a, $entities_b))
      + (0.15 * $this->sageJaccard($keywords_a, $keywords_b));
  }

  private function sageJaccard(array $a, array $b): float {
    $a = array_flip(array_filter($a, 'is_string'));
    $b = array_flip(array_filter($b, 'is_string'));
    if (empty($a) || empty($b)) {
      return 0.0;
    }
    $inter = count(array_intersect_key($a, $b));
    $union = count($a) + count($b) - $inter;
    return $union > 0 ? $inter / $union : 0.0;
  }

  private function sageClassifyEdgeType(array $entities_a, array $entities_b): string {
    if (empty($entities_a) || empty($entities_b)) {
      return 'semantic';
    }
    $parse = static function (array $list): array {
      $out = [];
      foreach (array_filter($list, 'is_string') as $e) {
        $colon = strpos($e, ':');
        if ($colon !== FALSE) {
          $out[strtolower(substr($e, $colon + 1))] = substr($e, 0, $colon);
        }
      }
      return $out;
    };
    $map_a   = $parse($entities_a);
    $map_b   = $parse($entities_b);
    $overlap = array_intersect_key($map_a, $map_b);
    if (empty($overlap)) {
      return 'semantic';
    }
    $types = array_values($overlap);
    foreach (['PERSON', 'EVENT', 'NORP', 'ORG', 'GPE'] as $priority) {
      if (in_array($priority, $types)) {
        return $priority;
      }
    }
    return 'semantic';
  }

  private function sageExtractArrayField(mixed $value): array {
    if (is_array($value)) {
      if (isset($value['Data']['StringData']['data']) && is_array($value['Data']['StringData']['data'])) {
        return $value['Data']['StringData']['data'];
      }
      return $value;
    }
    if (is_string($value)) {
      return json_decode($value, TRUE) ?? [];
    }
    return [];
  }

  /**
   * Returns NIDs of nodes whose given field is NULL or empty string.
   *
   * Catches both NULL (field never written) and empty string (field saved
   * blank). An OR condition group is required because plain text fields store
   * "" rather than NULL when submitted empty, so notExists() alone misses them.
   */
  private function queryNidsWithEmptyField(array $type_list, string $field): array {
    $query    = \Drupal::entityQuery('node')
      ->condition('type', $type_list, 'IN')
      ->accessCheck(FALSE);
    $empty_or = $query->orConditionGroup()
      ->notExists($field)
      ->condition($field, '', '=');
    return array_values($query->condition($empty_or)->execute());
  }

  /**
   * Parses a plain-text keyword response into a clean array.
   *
   * Strips numbering, bullets, surrounding punctuation, and blank lines.
   * Handles common model formatting drift without requiring JSON output.
   */
  private function parseKeywordLines(string $raw): array {
    $lines    = preg_split('/\r?\n/', trim($raw));
    $keywords = [];

    foreach ($lines as $line) {
      // Strip leading list markers: "1.", "1)", "-", "•", "*".
      $line = preg_replace('/^\s*[\d]+[\.\)]\s*/', '', $line);
      $line = preg_replace('/^\s*[-•*]\s*/', '', $line);
      // Strip surrounding quotes and stray punctuation.
      $line = trim($line, " \t\"',.;:");

      if ($line !== '') {
        $keywords[] = $line;
      }
    }

    return array_slice($keywords, 0, 8);
  }

}
