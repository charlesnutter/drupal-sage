<?php

declare(strict_types=1);

namespace Drupal\sage_content_discovery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles headless data extraction for the SAGE local processing pipeline.
 */
class SageExportController extends ControllerBase {

  /**
   * Exports sanitized published topic nodes for the pipeline.
   */
  public function exportData(Request $request): JsonResponse {
    $storage = $this->entityTypeManager()->getStorage('node');
    $nid = (int) $request->query->get('nid');

    // If an NID is passed, run an incremental update; otherwise, pull all 'topic' items.
    if ($nid > 0) {
      $nodes = $storage->loadByProperties(['nid' => $nid, 'status' => 1]);
    } else {
      $nodes = $storage->loadByProperties(['type' => 'topic', 'status' => 1]);
    }

    $payload = [];
    foreach ($nodes as $node) {
      $body_value = $node->hasField('body') && !$node->get('body')->isEmpty()
        ? $node->get('body')->value
        : '';

      $age_range = '';
      if ($node->hasField('field_age') && !$node->get('field_age')->isEmpty()) {
        $term = $node->get('field_age')->entity;
        $age_range = $term ? $term->label() : '';
      }

      $keywords = [];
      if ($node->hasField('field_keywords_plain') && !$node->get('field_keywords_plain')->isEmpty()) {
        $raw = $node->get('field_keywords_plain')->value;
        $keywords = array_values(array_filter(array_map('trim', explode(',', $raw))));
      }

      $payload[] = [
        'nid'       => (int) $node->id(),
        'title'     => $node->label(),
        'body'      => strip_tags($body_value),
        'age_range' => $age_range,
        'keywords'  => $keywords,
      ];
    }

    return new JsonResponse($payload);
  }
}
