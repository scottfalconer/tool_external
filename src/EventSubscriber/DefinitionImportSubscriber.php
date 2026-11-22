<?php

declare(strict_types=1);

namespace Drupal\tool_external\EventSubscriber;

use Drupal\Core\Config\ConfigImporterEvent;
use Drupal\Core\Config\ConfigImportValidateEventSubscriberBase;
use Drupal\tool_external\Config\StableConfigReader;
use Drupal\tool_external\Definition\DefinitionFactory;
use Drupal\tool_external\Entity\ExternalTool;

/**
 * Validates the complete staged tool catalog before configuration is written.
 */
final class DefinitionImportSubscriber extends ConfigImportValidateEventSubscriberBase {

  public function __construct(
    private readonly DefinitionFactory $definitions,
    private readonly StableConfigReader $stableConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function onConfigImporterValidate(ConfigImporterEvent $event): void {
    $importer = $event->getConfigImporter();
    $source = $importer->getStorageComparer()->getSourceStorage();
    foreach ($source->listAll('tool_external.tool.') as $name) {
      try {
        $data = $source->read($name);
        if (!is_array($data) || $name !== 'tool_external.tool.' . ($data['id'] ?? '')) {
          throw new \InvalidArgumentException('The configuration name and tool ID must agree.');
        }
        ExternalTool::validateDefinition($data, $this->definitions);
        $effective = $this->stableConfig->withOverrides($name, $data);
        ExternalTool::validateDefinition($effective, $this->definitions);
        if ($effective['id'] !== $data['id']) {
          throw new \InvalidArgumentException('A platform override cannot change the tool ID.');
        }
      }
      catch (\InvalidArgumentException $exception) {
        $importer->logError(sprintf('External tool configuration "%s" is invalid: %s', $name, $exception->getMessage()));
      }
    }
  }

}
