<?php

declare(strict_types=1);

namespace Drupal\sage_content_discovery\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[FunctionCall(
  id: 'sage_content_discovery:sage_collection_saver',
  function_name: 'sage_collection_saver',
  name: 'SAGE Collection Saver',
  description: 'Saves an array of selected source node IDs into a structured Drupal collection entity.',
  group: 'sage_content_discovery',
  context_definitions: [
    'collection_title' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Collection Title'),
      description: new TranslatableMarkup('The title/name of the collection to create.'),
      required: TRUE,
    ),
    'node_ids' => new ContextDefinition(
      data_type: 'list',
      label: new TranslatableMarkup('Node IDs'),
      description: new TranslatableMarkup('An array of integers representing Drupal node IDs to add to the collection.'),
      required: TRUE,
    ),
  ],
)]
class SageCollectionSaver extends FunctionCallBase implements ExecutableFunctionCallInterface {

  protected EntityTypeManagerInterface $entityTypeManager;

  protected string $output = '';

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
    );
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  public function execute(): void {
    $title = $this->getContextValue('collection_title');
    $nids  = (array) $this->getContextValue('node_ids');

    try {
      $node_storage = $this->entityTypeManager->getStorage('node');
      $collection   = $node_storage->create([
        'type'                  => 'research_collection',
        'title'                 => $title,
        'status'                => 1,
        'field_collected_items' => array_map(fn($id) => ['target_id' => (int) $id], $nids),
      ]);
      $collection->save();

      $url = Url::fromRoute('entity.node.canonical', ['node' => $collection->id()])->setAbsolute()->toString();
      $this->output = sprintf(
        'Successfully created collection "%s" with %d item(s). Collection URL: %s',
        $title,
        count($nids),
        $url,
      );
    }
    catch (\Exception $e) {
      $this->output = 'Failed to create collection: ' . $e->getMessage();
    }
  }

  public function getReadableOutput(): string {
    return $this->output;
  }

}
