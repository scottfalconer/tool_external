<?php

declare(strict_types=1);

namespace Drupal\tool_external\Plugin\tool\Tool;

use Drupal\Component\Plugin\DependentPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\Exception\RequirementsException;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool_external\Exception\ExternalToolException;
use Drupal\tool_external\Plugin\tool\Tool\Derivative\ExternalToolDeriver;
use Drupal\tool_external\Transport\ExternalClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs an external implementation through the normal Tool API pipeline.
 */
#[Tool(
  id: 'external',
  label: new TranslatableMarkup('External tool'),
  description: new TranslatableMarkup('A configured external tool.'),
  operation: ToolOperation::Transform,
  permission: 'execute external tools',
  deriver: ExternalToolDeriver::class,
)]
final class ExternalTool extends ToolBase implements DependentPluginInterface {

  /**
   * The HTTP transport used to invoke the configured function.
   */
  protected ExternalClient $client;

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies(): array {
    $id = $this->getDerivativeId();
    return $id === NULL || $id === '' ? [] : ['config' => ['tool_external.tool.' . $id]];
  }

  /**
   * {@inheritdoc}
   */
  public function checkRequirements(): void {
    try {
      $this->client->checkRequirements((string) $this->getDerivativeId());
    }
    catch (ExternalToolException $exception) {
      throw new RequirementsException($exception->getMessage(), previous: $exception);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    // Drop obsolete provenance values before PluginBase stores configuration.
    if (array_key_exists('tool_external_context', $configuration)) {
      $configuration['tool_external_context'] = NULL;
    }
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->client = $container->get('tool_external.client');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function doExecute(array $values): ExecutableResult {
    $id = $this->getDerivativeId();
    if ($id === NULL || $id === '') {
      return ExecutableResult::failure(new TranslatableMarkup('An external tool definition is required.'), NULL, FailureCategory::Runtime);
    }
    try {
      if (array_key_exists('tool_external_context', $this->configuration)) {
        throw new ExternalToolException('context', 'Use the delegated runner for custom invocation context.');
      }
      $outputs = $this->client->invoke($id, $values, $this->getInvoker());
      return ExecutableResult::success(new TranslatableMarkup('External tool completed.'), $outputs);
    }
    catch (ExternalToolException $exception) {
      // The transport supplies a safe public message. Tool API validates the
      // typed outputs.
      return ExecutableResult::failure(
        new TranslatableMarkup('External tool failed: @reason', ['@reason' => $exception->getMessage()]),
        NULL,
        $exception->failureCode() === 'input' ? FailureCategory::Input : FailureCategory::Runtime,
      );
    }
  }

}
