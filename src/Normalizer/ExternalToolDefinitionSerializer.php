<?php

declare(strict_types=1);

namespace Drupal\tool_external\Normalizer;

use Drupal\Core\Plugin\Context\ContextDefinitionInterface;
use Drupal\tool\Normalizer\ToolDefinitionSerializer;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\OutputDefinitionInterface;
use Drupal\tool_external\Plugin\tool\Tool\ExternalTool;

/**
 * Adds this provider's closed JSON objects to native Tool API schemas.
 *
 * Tool API intentionally leaves native maps and output envelopes open. External
 * tools have a stricter wire contract. Delegate all native normalization first,
 * including invoker transforms and constraints, then describe that restriction.
 */
final class ExternalToolDefinitionSerializer extends ToolDefinitionSerializer {

  public function __construct(private readonly ToolDefinitionSerializer $inner) {
    // Every public normalization entry point delegates to the existing service.
    // No second serializer or definition catalog is created by this decorator.
    parent::__construct(NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeInputDefinitions(ToolInterface $tool, string $format = 'json_schema', bool $include_locked = FALSE): array {
    $definitions = $this->inner->normalizeInputDefinitions($tool, $format, $include_locked);
    return $this->closeDefinitions($tool, $definitions, $format);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeInputSchema(ToolInterface $tool, string $format = 'json_schema', bool $include_locked = FALSE): array {
    return $this->closeForExternal($tool, $this->inner->normalizeInputSchema($tool, $format, $include_locked), $format);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeInputDefinition(ToolInterface $tool, string $name, InputDefinitionInterface|ContextDefinitionInterface $definition, string $format = 'json_schema'): array {
    return $this->closeForExternal($tool, $this->inner->normalizeInputDefinition($tool, $name, $definition, $format), $format);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeOutputDefinitions(ToolInterface $tool, string $format = 'json_schema'): array {
    return $this->closeDefinitions($tool, $this->inner->normalizeOutputDefinitions($tool, $format), $format);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeOutputSchema(ToolInterface $tool, string $format = 'json_schema'): array {
    return $this->closeForExternal($tool, $this->inner->normalizeOutputSchema($tool, $format), $format);
  }

  /**
   * {@inheritdoc}
   */
  public function normalizeOutputDefinition(ToolInterface $tool, string $name, OutputDefinitionInterface $definition, string $format = 'json_schema'): array {
    return $this->closeForExternal($tool, $this->inner->normalizeOutputDefinition($tool, $name, $definition, $format), $format);
  }

  /**
   * Adjusts named definitions without treating their names as schema keywords.
   */
  private function closeDefinitions(ToolInterface $tool, array $definitions, string $format): array {
    foreach ($definitions as $name => $schema) {
      $definitions[$name] = $this->closeForExternal($tool, $schema, $format);
    }
    return $definitions;
  }

  /**
   * Leaves other providers and non-schema normalization formats unchanged.
   */
  private function closeForExternal(ToolInterface $tool, array $schema, string $format): array {
    return $tool instanceof ExternalTool && $format === 'json_schema'
      ? $this->closeObjects($schema)
      : $schema;
  }

  /**
   * Walks only schema-bearing keywords, never examples/default/enum data.
   */
  private function closeObjects(array $schema): array {
    $types = (array) ($schema['type'] ?? []);
    if (in_array('object', $types, TRUE)) {
      $schema['additionalProperties'] = FALSE;
    }
    foreach (['properties', '$defs', 'definitions'] as $keyword) {
      if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
        foreach ($schema[$keyword] as $name => $child) {
          if (is_array($child)) {
            $schema[$keyword][$name] = $this->closeObjects($child);
          }
        }
      }
    }
    foreach (['items', 'contains', 'not', 'if', 'then', 'else'] as $keyword) {
      if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
        $schema[$keyword] = $this->closeObjects($schema[$keyword]);
      }
    }
    foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $keyword) {
      if (isset($schema[$keyword]) && is_array($schema[$keyword])) {
        foreach ($schema[$keyword] as $index => $child) {
          if (is_array($child)) {
            $schema[$keyword][$index] = $this->closeObjects($child);
          }
        }
      }
    }
    return $schema;
  }

}
