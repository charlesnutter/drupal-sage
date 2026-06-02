<?php

namespace Drupal\sage_content_discovery;

/**
 * Request-scoped registry that passes tool results to the chat controller.
 *
 * The SageGraphRetriever tool writes here during execute(); the controller
 * reads and clears the registry after the agent finishes. Because both happen
 * within the same PHP request there is no persistence concern.
 */
class SageResultRegistry {

  private static ?array $results = NULL;

  public static function store(array $results): void {
    static::$results = $results;
  }

  public static function consume(): ?array {
    $data = static::$results;
    static::$results = NULL;
    return $data;
  }

}
