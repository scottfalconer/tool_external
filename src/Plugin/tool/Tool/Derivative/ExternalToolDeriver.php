<?php

declare(strict_types=1);

namespace Drupal\tool_external\Plugin\tool\Tool\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Tool\ToolDefinition;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool_external\Config\StableConfigReader;
use Drupal\tool_external\Definition\DefinitionFactory;
use Drupal\tool_external\Entity\ExternalTool;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registers external tools using local, effective Drupal configuration only.
 */
final class ExternalToolDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly DefinitionFactory $definitionFactory,
    private readonly LoggerInterface $logger,
    private readonly StableConfigReader $stableConfig,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new self(
      $container->get('config.factory'),
      $container->get('tool_external.definition_factory'),
      $container->get('logger.channel.tool_external'),
      $container->get('tool_external.stable_config'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    // Tool API retains the deriver across cache clears. Rebuild this local map.
    $this->derivatives = [];
    $base = $base_plugin_definition instanceof ToolDefinition
      ? $base_plugin_definition
      : new ToolDefinition($base_plugin_definition);
    foreach ($this->configFactory->listAll('tool_external.tool.') as $name) {
      // Cache only deployment-stable settings.php overrides, never a request's
      // language/domain overrides. Execution uses this same reader.
      $data = $this->stableConfig->read($name);
      if (($data['status'] ?? FALSE) !== TRUE) {
        continue;
      }
      try {
        ExternalTool::validateDefinition($data, $this->definitionFactory);
        $id = $data['id'];
        if ($name !== 'tool_external.tool.' . $id) {
          throw new \InvalidArgumentException('Configuration name and tool id must agree.');
        }
        $this->derivatives[$id] = new ToolDefinition([
          'id' => $base->id() . ':' . $id,
          'label' => new TranslatableMarkup('@label', ['@label' => $data['label']]),
          'description' => new TranslatableMarkup('@description', ['@description' => $data['description']]),
          'provider' => 'tool_external',
          'class' => $base->getClass(),
          'operation' => ToolOperation::from($data['operation']),
          'permission' => $data['permission'],
          'input_definitions' => $this->definitionFactory->inputs($data['inputs']),
          'output_definitions' => $this->definitionFactory->outputs($data['outputs']),
        ]);
      }
      catch (\InvalidArgumentException $exception) {
        // An invalid local definition must not make the whole catalog unusable.
        $this->logger->error('External tool configuration @name was skipped: @reason', [
          '@name' => $name,
          '@reason' => $exception->getMessage(),
        ]);
      }
    }
    return $this->derivatives;
  }

}
