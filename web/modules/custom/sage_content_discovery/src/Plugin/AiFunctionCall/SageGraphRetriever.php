<?php

declare(strict_types=1);

namespace Drupal\sage_content_discovery\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\key\KeyRepositoryInterface;
use Drupal\sage_content_discovery\SageResultRegistry;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[FunctionCall(
  id: 'sage_content_discovery:sage_graph_retriever',
  function_name: 'sage_graph_retriever',
  name: 'SAGE Graph Knowledge Engine',
  description: 'Queries Milvus vector DB and expands neighboring structural nodes into a cohesive JSON-LD knowledge graph context.',
  group: 'sage_content_discovery',
  context_definitions: [
    'user_query' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('User Query'),
      description: new TranslatableMarkup('The user prompt or question to resolve semantics against.'),
      required: TRUE,
    ),
    'age_range' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Age Range'),
      description: new TranslatableMarkup("One of '5-8', '9-12', '13-15', '16-18'. Leave empty to search all ages."),
      required: FALSE,
      default_value: '',
    ),
    'keywords' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Keywords'),
      description: new TranslatableMarkup("Comma-separated boost hints, e.g. 'nonviolent protest, civil disobedience'."),
      required: FALSE,
      default_value: '',
    ),
  ],
)]
class SageGraphRetriever extends FunctionCallBase implements ExecutableFunctionCallInterface {

  const NEIGHBOR_TOP_K = 25;

  // Number of top hop-1 neighbors to expand from for hop-2 traversal.
  // Keeps the second-hop candidate set tractable.
  const HOP2_TOP_EXPAND = 10;

  const STOP_WORDS = [
    'a', 'an', 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
    'of', 'with', 'by', 'is', 'was', 'are', 'were', 'be', 'been', 'being',
    'have', 'has', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
    'should', 'may', 'might', 'shall', 'can', 'this', 'that', 'these',
    'those', 'it', 'its', 'as', 'from', 'not', 'no', 'so', 'if', 'about',
  ];

  protected ClientInterface $httpClient;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected KeyRepositoryInterface $keyRepository;

  protected LoggerChannelFactoryInterface $loggerFactory;

  protected RequestStack $requestStack;

  protected string $output = '';

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->httpClient        = $container->get('http_client');
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->keyRepository     = $container->get('key.repository');
    $instance->loggerFactory     = $container->get('logger.factory');
    $instance->requestStack      = $container->get('request_stack');
    return $instance;
  }

  public function execute(): void {
    $query     = $this->getContextValue('user_query');
    $age_range = $this->getContextValue('age_range') ?? '';

    // FOR TESTING: logs the exact query string the agent passes to the retriever.
    $this->loggerFactory->get('sage_content_discovery')->info('SAGE retrieval query: @q', ['@q' => $query]);
    $keywords  = !empty($this->getContextValue('keywords'))
      ? array_map('trim', explode(',', $this->getContextValue('keywords')))
      : [];

    // Phase 1: Embed the query via Google gemini-embedding-2.
    $google_api_key = $this->keyRepository->getKey('google_embedding_key')?->getKeyValue();
    if (empty($google_api_key)) {
      $this->output = json_encode(['error' => 'Google embedding API key not configured (key: google_embedding_key).']);
      return;
    }

    try {
      $embed_response = $this->httpClient->request('POST', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-2:embedContent?key=' . $google_api_key, [
        'json' => [
          'model'    => 'models/gemini-embedding-2',
          'content'  => ['parts' => [['text' => $query]]],
          'taskType' => 'RETRIEVAL_QUERY',
        ],
      ]);
      $query_vector = json_decode($embed_response->getBody()->getContents(), TRUE)['embedding']['values'] ?? [];

      if (empty($query_vector)) {
        $this->output = json_encode(['error' => 'Google embedding API returned an empty vector.']);
        return;
      }
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('sage_content_discovery')->error('Google embed failed: @msg', ['@msg' => $e->getMessage()]);
      $this->output = json_encode(['error' => 'Embedding service unavailable: ' . $e->getMessage()]);
      return;
    }

    // Phase 2: Build metadata filter expression and run seed retrieval.
    $filter_expr = $this->buildFilterExpression($age_range);

    $seed_request = [
      'collectionName' => 'sage_knowledge_graph',
      'vector'         => $query_vector,
      'limit'          => 5,
      'outputFields'   => ['chunk_id', 'text', 'source_nid', 'entities', 'neighbors', 'age_range', 'keywords'],
    ];

    if (!empty($filter_expr)) {
      $seed_request['filter'] = $filter_expr;
    }

    try {
      $seed_response = $this->httpClient->request('POST', 'http://milvus:19530/v1/vector/search', [
        'json' => $seed_request,
      ]);
      $seeds = json_decode($seed_response->getBody()->getContents(), TRUE)['data'] ?? [];
    }
    catch (\Exception $e) {
      $this->output = json_encode(['error' => 'Milvus seed search failed: ' . $e->getMessage()]);
      return;
    }

    if (empty($seeds)) {
      $filter_note = !empty($filter_expr) ? " (filter: $filter_expr)" : '';
      $this->output = json_encode(['error' => "No seed nodes found in Milvus$filter_note."]);
      return;
    }

    // Phase 3: Parse "TYPE:chunk_id" neighbor strings from seeds.
    // When the same neighbor appears in multiple seeds with different edge types,
    // the highest-priority type wins (PERSON > EVENT > NORP > ORG > GPE > semantic).
    $seed_ids               = [];
    $neighbor_ids           = [];
    $neighbor_edge_type_map = [];

    foreach ($seeds as $seed) {
      $seed_ids[] = $seed['chunk_id'];
      foreach ($this->extractArrayField($seed['neighbors'] ?? []) as $encoded) {
        if (!is_string($encoded) || $encoded === '') {
          continue;
        }
        ['edge_type' => $edge_type, 'neighbor_id' => $neighbor_id] = $this->parseEncodedNeighbor($encoded);

        if (in_array($neighbor_id, $seed_ids)) {
          continue;
        }
        $neighbor_ids[] = $neighbor_id;
        if (!isset($neighbor_edge_type_map[$neighbor_id])) {
          $neighbor_edge_type_map[$neighbor_id] = $edge_type;
        }
        elseif ($this->edgeTypePriority($edge_type) > $this->edgeTypePriority($neighbor_edge_type_map[$neighbor_id])) {
          $neighbor_edge_type_map[$neighbor_id] = $edge_type;
        }
      }
    }

    $neighbor_ids = array_values(array_unique(array_diff($neighbor_ids, $seed_ids)));

    // Phase 4: Bulk-fetch neighbor content from Milvus.
    $expanded_neighbors = [];
    if (!empty($neighbor_ids)) {
      try {
        $lookup_response = $this->httpClient->request('POST', 'http://milvus:19530/v1/vector/get', [
          'json' => [
            'collectionName' => 'sage_knowledge_graph',
            'id'             => array_values($neighbor_ids),
            'outputFields'   => ['chunk_id', 'text', 'source_nid', 'entities', 'vector', 'neighbors', 'age_range', 'keywords'],
          ],
        ]);
        $expanded_neighbors = json_decode($lookup_response->getBody()->getContents(), TRUE)['data'] ?? [];
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('sage_content_discovery')->warning('Neighbor fetch failed, proceeding with seeds only: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    // Annotate hop-1 neighbors with edge type and hop distance.
    foreach ($expanded_neighbors as &$neighbor) {
      $neighbor['_edge_type'] = $neighbor_edge_type_map[$neighbor['chunk_id']] ?? 'semantic';
      $neighbor['_hop']       = 1;
    }
    unset($neighbor);

    // Phase 4b: Two-hop graph expansion.
    // Take the top HOP2_TOP_EXPAND hop-1 neighbors (by raw dot-product score)
    // and follow their pre-computed edges one level deeper. This surfaces
    // content that is structurally related to the seeds but too distant for
    // ANN search to surface directly.
    $all_seen_ids = array_merge($seed_ids, $neighbor_ids);
    $hop2_candidates = $this->collectHop2Candidates(
      $expanded_neighbors,
      $all_seen_ids,
      $query_vector,
      self::HOP2_TOP_EXPAND,
    );

    $expanded_hop2 = [];
    if (!empty($hop2_candidates)) {
      $hop2_ids = array_keys($hop2_candidates);
      try {
        $hop2_response = $this->httpClient->request('POST', 'http://milvus:19530/v1/vector/get', [
          'json' => [
            'collectionName' => 'sage_knowledge_graph',
            'id'             => array_values($hop2_ids),
            'outputFields'   => ['chunk_id', 'text', 'source_nid', 'entities', 'vector'],
          ],
        ]);
        $expanded_hop2 = json_decode($hop2_response->getBody()->getContents(), TRUE)['data'] ?? [];
      }
      catch (\Exception $e) {
        $this->loggerFactory->get('sage_content_discovery')->warning('Hop-2 fetch failed, continuing with hop-1 only: @msg', ['@msg' => $e->getMessage()]);
      }

      foreach ($expanded_hop2 as &$node) {
        $node['_edge_type'] = $hop2_candidates[$node['chunk_id']] ?? 'semantic';
        $node['_hop']       = 2;
      }
      unset($node);
    }

    $all_neighbors = array_merge($expanded_neighbors, $expanded_hop2);

    // Phase 5: Rerank all candidates by cosine similarity with edge-type intent
    // weights. Hop-2 nodes carry a distance penalty applied inside the reranker.
    $intent_weights = $this->detectQueryIntent($query);
    $reranked_neighbors = $this->rerankByQueryVector($query_vector, $query, $all_neighbors, self::NEIGHBOR_TOP_K, $intent_weights, $keywords);

    // Phase 6: Batch-load node titles for all returned source NIDs.
    $all_nids = array_unique(array_merge(
      array_column($seeds, 'source_nid'),
      array_column($reranked_neighbors, 'source_nid'),
    ));
    $node_entities = $this->entityTypeManager->getStorage('node')->loadMultiple($all_nids);
    $node_titles   = [];
    foreach ($node_entities as $nid => $entity) {
      $node_titles[$nid] = $entity->label();
    }

    // Phase 7: Build clean JSON for the model to populate the structured output schema.
    $seen_nids    = [];
    $results      = [];
    $all_entities = [];

    foreach (array_merge($seeds, $reranked_neighbors) as $chunk) {
      $nid = (int) $chunk['source_nid'];
      if (isset($seen_nids[$nid])) {
        continue;
      }
      $seen_nids[$nid] = TRUE;

      $raw_entities   = array_filter($this->extractArrayField($chunk['entities'] ?? []), 'is_string');
      // Strip "TYPE:" prefix added during pipeline Pass 1 — agents see plain entity text.
      $plain_entities = array_map($this->stripEntityTypePrefix(...), $raw_entities);

      $results[] = [
        'nid'      => $nid,
        'title'    => $node_titles[$nid] ?? '',
        'url'      => Url::fromRoute('entity.node.canonical', ['node' => $nid])->setAbsolute()->toString(),
        'text'     => $chunk['text'],
        'entities' => $plain_entities,
      ];

      foreach ($plain_entities as $entity) {
        $all_entities[] = $entity;
      }
    }

    // Phase 8: Named entity expansion — surface topic pages for entities
    // mentioned in retrieved chunks that didn't appear through graph traversal.
    $expansion = $this->expandWithEntityTopicPages(
      array_merge($seeds, $reranked_neighbors),
      $seen_nids,
    );
    foreach ($expansion as $exp) {
      $results[] = $exp;
      foreach ($exp['entities'] as $entity) {
        $all_entities[] = $entity;
      }
    }

    $payload = [
      'result_count' => count($results),
      'results'      => $results,
      'all_entities' => array_slice(array_unique($all_entities), 0, 30),
    ];

    // Store for the controller to pick up after the agent finishes.
    SageResultRegistry::store($payload);

    $this->output = json_encode($payload, JSON_PRETTY_PRINT);
  }

  public function getReadableOutput(): string {
    return $this->output;
  }

  private function buildFilterExpression(string $age_range): string {
    if ($age_range !== '') {
      return 'age_range == "' . addslashes($age_range) . '"';
    }
    return '';
  }

  private function rerankByQueryVector(array $query_vector, string $query, array $neighbors, int $top_k, array $intent_weights = [], array $keyword_hints = []): array {
    if (empty($neighbors)) {
      return [];
    }

    $with_vectors    = [];
    $without_vectors = [];

    foreach ($neighbors as $neighbor) {
      $vec = $neighbor['vector'] ?? [];
      if (!empty($vec) && count($vec) === count($query_vector)) {
        $score     = $this->dotProduct($query_vector, $vec);
        $edge_type = $neighbor['_edge_type'] ?? 'semantic';
        $score    *= ($intent_weights[$edge_type] ?? 1.0);
        if (($neighbor['_hop'] ?? 1) === 2) {
          $score *= 0.75;
        }
        $score *= $this->keywordBoostMultiplier($keyword_hints, $this->extractArrayField($neighbor['keywords'] ?? []));
        $neighbor['_rerank_score'] = $score;
        $with_vectors[] = $neighbor;
      }
      else {
        $without_vectors[] = $neighbor;
      }
    }

    usort($with_vectors, fn($a, $b) => $b['_rerank_score'] <=> $a['_rerank_score']);

    if (!empty($without_vectors)) {
      $fallback = $this->rerankNeighbors($query, $without_vectors, $top_k, $keyword_hints, $intent_weights);
      $with_vectors = array_merge($with_vectors, $fallback);
    }

    return array_slice($with_vectors, 0, $top_k);
  }

  private function dotProduct(array $a, array $b): float {
    $sum = 0.0;
    $len = count($a);
    for ($i = 0; $i < $len; $i++) {
      $sum += $a[$i] * $b[$i];
    }
    return $sum;
  }

  private function rerankNeighbors(string $query, array $neighbors, int $top_k, array $keyword_hints = [], array $intent_weights = []): array {
    if (empty($neighbors)) {
      return [];
    }

    $query_terms = $this->tokenize($query);

    if (empty($query_terms)) {
      return array_slice($neighbors, 0, $top_k);
    }

    foreach ($neighbors as &$node) {
      $text = strtolower($node['text'] ?? '');

      $tf = 0;
      foreach ($query_terms as $term) {
        $tf += substr_count($text, $term);
      }

      $entity_boost = 0;
      foreach (array_filter($this->extractArrayField($node['entities'] ?? []), 'is_string') as $entity) {
        $entity_lower = strtolower($this->stripEntityTypePrefix($entity));
        foreach ($query_terms as $term) {
          if (str_contains($entity_lower, $term)) {
            $entity_boost++;
          }
        }
      }

      $text_length  = max(str_word_count($text), 1);
      $base_score   = ($tf / sqrt($text_length)) + ($entity_boost * 0.5);
      $edge_type    = $node['_edge_type'] ?? 'semantic';
      $hop_penalty  = ($node['_hop'] ?? 1) === 2 ? 0.75 : 1.0;
      $kw_multiplier = $this->keywordBoostMultiplier($keyword_hints, $this->extractArrayField($node['keywords'] ?? []));
      $node['_rerank_score'] = $base_score * ($intent_weights[$edge_type] ?? 1.0) * $hop_penalty * $kw_multiplier;
    }
    unset($node);

    usort($neighbors, fn($a, $b) => $b['_rerank_score'] <=> $a['_rerank_score']);

    return array_slice($neighbors, 0, $top_k);
  }

  private function collectHop2Candidates(array $hop1_neighbors, array $seen_ids, array $query_vector, int $top_expand): array {
    if (empty($hop1_neighbors)) {
      return [];
    }

    // Score hop-1 nodes by dot product and take the top $top_expand to expand from.
    $scored = [];
    foreach ($hop1_neighbors as $neighbor) {
      $vec = $neighbor['vector'] ?? [];
      if (!empty($vec) && count($vec) === count($query_vector)) {
        $scored[] = ['node' => $neighbor, 'score' => $this->dotProduct($query_vector, $vec)];
      }
    }
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    $expand_from = array_slice($scored, 0, $top_expand);

    // Collect hop-2 candidate IDs and their dominant edge type.
    $candidates = [];
    foreach ($expand_from as $item) {
      foreach (array_filter($this->extractArrayField($item['node']['neighbors'] ?? []), 'is_string') as $encoded) {
        ['edge_type' => $edge_type, 'neighbor_id' => $neighbor_id] = $this->parseEncodedNeighbor($encoded);

        if (in_array($neighbor_id, $seen_ids)) {
          continue;
        }
        // Use highest-priority edge type when the same candidate appears via
        // multiple hop-1 nodes, consistent with Phase 3 conflict resolution.
        if (!isset($candidates[$neighbor_id])) {
          $candidates[$neighbor_id] = $edge_type;
        }
        elseif ($this->edgeTypePriority($edge_type) > $this->edgeTypePriority($candidates[$neighbor_id])) {
          $candidates[$neighbor_id] = $edge_type;
        }
      }
    }

    return $candidates;
  }

  private function detectQueryIntent(string $query): array {
    $weights = [
      'PERSON'   => 1.0,
      'ORG'      => 1.0,
      'GPE'      => 1.0,
      'EVENT'    => 1.0,
      'NORP'     => 1.0,
      'semantic' => 1.0,
    ];

    // Heuristic intent detection: no external service required.
    // Edge-type weights align with how spaCy classified entities at index time.
    $ner_types = $this->detectEntityTypesFromQuery($query);
    foreach ($ner_types as $type) {
      $weights[$type] = match($type) {
        'PERSON', 'ORG' => 1.4,
        'GPE', 'EVENT'  => 1.3,
        'NORP'          => 1.2,
        default         => 1.0,
      };
    }

    // Blend in user-selected entity type preferences stored in the session.
    // max() ensures an explicit user selection never lowers a weight that NER
    // already pushed higher — the stronger signal always wins.
    $session_hints = $this->requestStack->getCurrentRequest()->getSession()->get('sage_entity_type_hints', []);
    foreach ($session_hints as $type) {
      $hint_weight = match($type) {
        'PERSON', 'ORG' => 1.4,
        'GPE', 'EVENT'  => 1.3,
        'NORP'          => 1.2,
        default         => 1.0,
      };
      $weights[$type] = max($weights[$type] ?? 1.0, $hint_weight);
    }

    return $weights;
  }

  /**
   * Detects likely entity types present in a query using generic heuristic signals.
   *
   * Also checks corpus-specific entity names stored in the Drupal key-value store
   * under 'sage'/'corpus_signals' (imported via sage:import-signals). This
   * replaces the Flask/spaCy NER sidecar call so retrieval works in any environment.
   */
  private function detectEntityTypesFromQuery(string $query): array {
    $q        = strtolower($query);
    $detected = [];

    $signals = [
      'PERSON' => [
        'who is', 'who was', 'who were', 'whose', 'whom',
        'attorney', 'lawyer', 'judge', 'justice', 'minister', 'reverend', 'pastor',
        'senator', 'congressman', 'president', 'governor', 'mayor',
        'general', 'colonel', 'captain', 'officer',
        'professor', 'doctor', 'activist', 'organizer', 'leader',
        'founder', 'director', 'chairman', 'spokesperson',
        'born', 'died', 'biography', 'life of', 'legacy of', 'career of',
        'son of', 'daughter of', 'wife of', 'husband of',
      ],
      'ORG' => [
        'organization', 'association', 'committee', 'coalition', 'council',
        'conference', 'union', 'federation', 'alliance', 'bureau', 'agency',
        'church', 'mosque', 'synagogue', 'temple', 'diocese',
        'congress', 'senate', 'parliament', 'department', 'ministry', 'administration',
        'university', 'college', 'school', 'institute', 'academy',
        'court', 'tribunal', 'commission',
        'newspaper', 'magazine', 'press', 'broadcast', 'network',
        'corporation', 'company', 'firm', 'enterprise', 'nonprofit',
      ],
      'GPE' => [
        'where', 'location of', 'place', 'city', 'state', 'country', 'nation',
        'county', 'district', 'region', 'territory', 'province',
        'town', 'village', 'community', 'neighborhood',
        'capital', 'courthouse', 'statehouse', 'campus',
      ],
      'EVENT' => [
        'march', 'protest', 'demonstration', 'rally', 'sit-in', 'sit in',
        'boycott', 'strike', 'walkout', 'freedom ride',
        'trial', 'hearing', 'lawsuit', 'ruling', 'legislation', 'bill', 'amendment',
        'riot', 'violence', 'attack', 'bombing', 'assassination',
        'commemoration', 'anniversary', 'memorial', 'ceremony',
        'when did', 'during the', 'following the',
      ],
      'NORP' => [
        'community', 'population', 'minority', 'majority',
        'denomination', 'faith', 'religion', 'sect',
        'party', 'movement', 'ideology', 'political group',
        'citizens', 'voters', 'membership',
        'ethnic', 'cultural identity', 'national identity',
      ],
    ];

    foreach ($signals as $type => $terms) {
      foreach ($terms as $term) {
        if (str_contains($q, $term)) {
          $detected[] = $type;
          break;
        }
      }
    }

    // Augment with corpus-specific entity names imported via sage:import-signals.
    // Only checks types not already detected to avoid redundant loops.
    $corpus = \Drupal::keyValue('sage')->get('corpus_signals', []);
    foreach ($corpus as $type => $names) {
      if (!is_array($names) || in_array($type, $detected)) {
        continue;
      }
      foreach ($names as $name) {
        if (str_contains($q, strtolower($name))) {
          $detected[] = $type;
          break;
        }
      }
    }

    return $detected;
  }

  private function keywordBoostMultiplier(array $keyword_hints, array $node_keywords): float {
    if (empty($keyword_hints) || empty($node_keywords)) {
      return 1.0;
    }
    $node_keywords_lower = array_map('strtolower', $node_keywords);
    $overlap = 0;
    foreach ($keyword_hints as $hint) {
      $hint_lower = strtolower(trim($hint));
      foreach ($node_keywords_lower as $kw) {
        if (str_contains($kw, $hint_lower) || str_contains($hint_lower, $kw)) {
          $overlap++;
          break;
        }
      }
    }
    return 1.0 + (0.3 * ($overlap / count($keyword_hints)));
  }

  private function edgeTypePriority(string $type): int {
    return match($type) {
      'PERSON' => 5,
      'EVENT'  => 4,
      'NORP'   => 3,
      'ORG'    => 2,
      'GPE'    => 1,
      default  => 0,
    };
  }

  /**
   * Splits a "TYPE:chunk_id" encoded neighbor string into its components.
   *
   * Falls back to edge_type "semantic" for plain chunk IDs written before the
   * TYPE prefix was introduced.
   */
  private function parseEncodedNeighbor(string $encoded): array {
    $colon = strpos($encoded, ':');
    if ($colon !== FALSE) {
      return [
        'edge_type'   => substr($encoded, 0, $colon),
        'neighbor_id' => substr($encoded, $colon + 1),
      ];
    }
    return ['edge_type' => 'semantic', 'neighbor_id' => $encoded];
  }

  /**
   * Strips the "TYPE:" prefix from an entity string stored by the pipeline.
   *
   * Returns the plain entity text the agent and user should see.
   */
  private function stripEntityTypePrefix(string $entity): string {
    return str_contains($entity, ':') ? substr($entity, strpos($entity, ':') + 1) : $entity;
  }

  /**
   * Looks up topic pages for named entities mentioned in retrieved chunks.
   *
   * Entities that appear in chunk text but lack a surviving graph edge to any
   * seed won't surface through traversal alone. This method queries Drupal for
   * topic nodes whose title exactly matches those entity names, then fetches a
   * representative chunk from Milvus. Capped at 5 results to avoid bloat.
   */
  private function expandWithEntityTopicPages(array $chunks, array $seen_nids): array {
    $entity_counts   = [];
    $entity_type_map = [];

    // FOR TESTING: log the raw entities field from the first chunk.
    if (!empty($chunks)) {
      $first = reset($chunks);
      $this->loggerFactory->get('sage_content_discovery')->info(
        'SAGE expansion first chunk entities (type=@type, count=@count): @raw',
        [
          '@type'  => gettype($first['entities'] ?? null),
          '@count' => count($chunks),
          '@raw'   => substr(json_encode($first['entities'] ?? null), 0, 300),
        ]
      );
    }

    foreach ($chunks as $chunk) {
      foreach (array_filter($this->extractArrayField($chunk['entities'] ?? []), 'is_string') as $encoded) {
        $colon = strpos($encoded, ':');
        $type  = $colon !== FALSE ? substr($encoded, 0, $colon) : 'semantic';
        $name  = $colon !== FALSE ? substr($encoded, $colon + 1) : $encoded;

        $entity_counts[$name] = ($entity_counts[$name] ?? 0) + 1;
        if (!isset($entity_type_map[$name]) ||
            $this->edgeTypePriority($type) > $this->edgeTypePriority($entity_type_map[$name])) {
          $entity_type_map[$name] = $type;
        }
      }
    }

    arsort($entity_counts);
    $candidates   = array_slice(array_keys($entity_counts), 0, 15);
    $node_storage = $this->entityTypeManager->getStorage('node');
    $expansion    = [];
    $logger       = $this->loggerFactory->get('sage_content_discovery');

    // FOR TESTING: log entity candidates being checked for expansion.
    $logger->info('SAGE entity expansion candidates: @list', ['@list' => implode(', ', $candidates)]);

    foreach ($candidates as $entity_name) {
      if (count($expansion) >= 5) {
        break;
      }

      $matching = $node_storage->getQuery()
        ->condition('title', $entity_name)
        ->condition('type', 'topic')
        ->condition('status', 1)
        ->accessCheck(FALSE)
        ->range(0, 1)
        ->execute();

      if (empty($matching)) {
        // FOR TESTING: log misses so entity name vs title mismatches are visible.
        $logger->info('SAGE expansion: no topic node found for "@name"', ['@name' => $entity_name]);
        continue;
      }

      $nid = (int) reset($matching);
      if (isset($seen_nids[$nid])) {
        $logger->info('SAGE expansion: nid @nid (@name) already in results', ['@nid' => $nid, '@name' => $entity_name]);
        continue;
      }

      try {
        $response = $this->httpClient->request('POST', 'http://milvus:19530/v1/vector/query', [
          'json' => [
            'collectionName' => 'sage_knowledge_graph',
            'filter'         => 'source_nid == ' . $nid,
            'outputFields'   => ['chunk_id', 'text', 'source_nid', 'entities', 'keywords'],
            'limit'          => 1,
          ],
        ]);
        $raw  = $response->getBody()->getContents();
        $rows = json_decode($raw, TRUE)['data'] ?? [];
        // FOR TESTING: log Milvus response for expansion queries.
        $logger->info('SAGE expansion Milvus query for nid @nid: @resp', ['@nid' => $nid, '@resp' => substr($raw, 0, 300)]);
      }
      catch (\Exception $e) {
        $logger->warning('SAGE expansion Milvus query failed for nid @nid: @msg', ['@nid' => $nid, '@msg' => $e->getMessage()]);
        continue;
      }

      if (empty($rows)) {
        continue;
      }

      $chunk          = $rows[0];
      $node           = $node_storage->load($nid);
      $plain_entities = array_map(
        $this->stripEntityTypePrefix(...),
        array_filter($chunk['entities'] ?? [], 'is_string'),
      );

      $expansion[]     = [
        'nid'      => $nid,
        'title'    => $node ? $node->label() : $entity_name,
        'url'      => Url::fromRoute('entity.node.canonical', ['node' => $nid])->setAbsolute()->toString(),
        'text'     => $chunk['text'],
        'entities' => $plain_entities,
      ];
      $seen_nids[$nid] = TRUE;
    }

    return $expansion;
  }

  /**
   * Unpacks ARRAY fields returned by the Milvus v1 REST API.
   *
   * The v1 API wraps array fields in a protobuf envelope:
   *   {"Data": {"StringData": {"data": [...]}}}
   * rather than returning a plain PHP array. This helper normalises both
   * forms so callers don't need to know which Milvus API version is active.
   */
  private function extractArrayField(mixed $value): array {
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

  private function tokenize(string $text): array {
    $terms = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z0-9\s]/', '', $text)));
    return array_values(array_filter(
      $terms,
      fn($t) => strlen($t) > 2 && !in_array($t, self::STOP_WORDS)
    ));
  }

}
