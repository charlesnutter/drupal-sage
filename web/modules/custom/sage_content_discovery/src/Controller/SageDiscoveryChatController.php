<?php

namespace Drupal\sage_content_discovery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\ai\AiProviderPluginManager;
use Drupal\ai\OperationType\Chat\ChatMessage;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\ai_agents\PluginManager\AiAgentManager;
use Drupal\sage_content_discovery\SageResultRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles AJAX chat messages for the SAGE discovery interface.
 */
class SageDiscoveryChatController extends ControllerBase {

  const SESSION_KEY    = 'sage_chat_history';
  const HINTS_KEY      = 'sage_entity_type_hints';
  const AGENT_ID       = 'sage_researcher';
  const VALID_TYPES    = ['PERSON', 'ORG', 'GPE', 'EVENT', 'NORP'];

  public function __construct(
    protected AiAgentManager $agentManager,
    protected AiProviderPluginManager $providerManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('plugin.manager.ai_agents'),
      $container->get('ai.provider'),
    );
  }

  public function chat(Request $request): JsonResponse {
    $data = json_decode($request->getContent(), TRUE) ?? [];
    $message = trim($data['message'] ?? '');
    $reset = !empty($data['reset']);

    if ($message === '') {
      return new JsonResponse(['error' => 'No message provided.'], 400);
    }

    $session = $request->getSession();

    if ($reset) {
      $session->remove(self::SESSION_KEY);
      // Sanitise and store entity type hints from the initial form submission.
      // Only recognised type labels are kept so arbitrary strings cannot reach
      // the retriever. Follow-up messages omit this key, leaving the stored
      // value in place for the duration of the conversation.
      $raw_hints = $data['entity_type_hints'] ?? [];
      $session->set(
        self::HINTS_KEY,
        array_values(array_intersect((array) $raw_hints, self::VALID_TYPES)),
      );
    }

    // Restore prior conversation (user + assistant turns only).
    $history = array_map(
      fn($m) => new ChatMessage($m['role'], $m['text']),
      $session->get(self::SESSION_KEY, []),
    );
    $history[] = new ChatMessage('user', $message);

    try {
      $agent = $this->agentManager->createInstance(self::AGENT_ID);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Could not load SAGE agent: ' . $e->getMessage()], 500);
    }

    try {
      $defaults = $this->providerManager->getDefaultProviderForOperationType('chat_with_tools');
      $provider = $this->providerManager->createInstance($defaults['provider_id']);
      $agent->setAiProvider($provider);
      $agent->setModelName($defaults['model_id']);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'No AI provider configured for chat_with_tools.'], 500);
    }

    $agent->setAiConfiguration([]);
    $agent->setCreateDirectly(TRUE);
    $agent->setChatHistory($history);

    try {
      $status = $agent->determineSolvability();
      $response_text = match ($status) {
        AiAgentInterface::JOB_SOLVABLE              => $agent->solve(),
        AiAgentInterface::JOB_NEEDS_ANSWERS         => implode("\n", $agent->askQuestion()),
        AiAgentInterface::JOB_SHOULD_ANSWER_QUESTION => $agent->answerQuestion(),
        AiAgentInterface::JOB_INFORMS               => $agent->inform(),
        default => NULL,
      };

      if ($response_text === NULL) {
        return new JsonResponse(['error' => 'Agent could not process the request.'], 500);
      }
    }
    catch (\Throwable $e) {
      \Drupal::logger('sage_content_discovery')->error('Agent error: @type @msg in @file:@line', [
        '@type' => get_class($e),
        '@msg'  => $e->getMessage(),
        '@file' => $e->getFile(),
        '@line' => $e->getLine(),
      ]);
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }

    // Persist only user/assistant turns — tool messages are internal context
    // that would require complex reconstruction across requests.
    $serializable = array_values(array_filter(
      $agent->getChatHistory(),
      fn($m) => in_array($m->getRole(), ['user', 'assistant']),
    ));
    $session->set(self::SESSION_KEY, array_map(
      fn($m) => ['role' => $m->getRole(), 'text' => $m->getText()],
      $serializable,
    ));

    $tool_results = SageResultRegistry::consume();
    $structured = $this->parseStructuredResponse($response_text);

    // When the retriever ran this turn, combine clean registry data (for links)
    // with any structured output the model produced (for message text and
    // search suggestions). Either or both may be present.
    if ($tool_results !== NULL) {
      return new JsonResponse([
        // Use the model's message field if structured output was found,
        // otherwise fall back to the raw response text.
        'response'           => $structured ? ($structured['message'] ?? '') : $response_text,
        'tool_results'       => $tool_results,
        'search_suggestions' => $structured['search_suggestions'] ?? [],
        'collection_title'   => $structured['collection_title_suggestion'] ?? '',
      ]);
    }

    // Non-retrieval turns (parameter gathering, collection saving, etc.).
    if ($structured) {
      return new JsonResponse(['response' => $structured['message'] ?? $response_text]);
    }

    return new JsonResponse(['response' => $response_text]);
  }

  /**
   * Attempts to parse a structured JSON response from the model.
   *
   * The model occasionally wraps its JSON in prose or markdown fences.
   * This method tries several strategies before giving up.
   *
   * @return array|null Decoded array with a 'response_type' key, or NULL.
   */
  private function parseStructuredResponse(string $text): ?array {
    // Strategy 1: the entire response is valid JSON.
    $parsed = json_decode($text, TRUE);
    if (json_last_error() === JSON_ERROR_NONE && isset($parsed['response_type'])) {
      return $parsed;
    }

    // Strategy 2: JSON is inside a ```json … ``` code fence.
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/i', $text, $m)) {
      $parsed = json_decode($m[1], TRUE);
      if (json_last_error() === JSON_ERROR_NONE && isset($parsed['response_type'])) {
        return $parsed;
      }
    }

    // Strategy 3: model prefixed the JSON with prose — find the first '{'.
    $pos = strpos($text, '{');
    if ($pos !== FALSE) {
      $parsed = json_decode(substr($text, $pos), TRUE);
      if (json_last_error() === JSON_ERROR_NONE && isset($parsed['response_type'])) {
        return $parsed;
      }
    }

    return NULL;
  }

}
