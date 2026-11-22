<?php

declare(strict_types=1);

namespace Drupal\tool_external\Definition;

use Drupal\Core\Validation\ConstraintManager;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\ListInputDefinition;
use Drupal\tool\TypedData\ListOutputDefinition;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\tool\TypedData\MapOutputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Drupal\tool\TypedData\OutputDefinitionInterface;

/**
 * Builds native Tool API definitions from their exportable configuration.
 *
 * This is deliberately a JSON-safe subset of Tool API's definitions. Entity
 * objects, services and PHP-specific typed data never become wire types.
 */
final class DefinitionFactory {

  /**
   * Pure value constraints and options supported by the external JSON contract.
   *
   * Native constraints may otherwise expose PHP callbacks, property paths or
   * local file access. In particular, normalizer is executable PHP even on
   * ordinary string constraints. Keep this list explicit when adding support.
   */
  private const CONSTRAINT_OPTIONS = [
    'Blank' => ['message'],
    'NotBlank' => ['message', 'allowNull'],
    'NotNull' => ['message'],
    'Null' => ['message'],
    'Length' => ['min', 'max', 'charset', 'countUnit', 'exactMessage', 'minMessage', 'maxMessage', 'charsetMessage'],
    'Count' => ['min', 'max', 'divisibleBy', 'exactMessage', 'minMessage', 'maxMessage', 'divisibleByMessage'],
    'Range' => ['min', 'max', 'notInRangeMessage', 'minMessage', 'maxMessage', 'invalidMessage'],
    'Regex' => ['pattern', 'match', 'message', 'htmlPattern'],
    'Choice' => [
      'choices', 'multiple', 'strict', 'min', 'max', 'message',
      'multipleMessage', 'minMessage', 'maxMessage', 'match',
    ],
    'AllowedValues' => [
      'choices', 'multiple', 'strict', 'min', 'max', 'message',
      'multipleMessage', 'minMessage', 'maxMessage', 'match',
    ],
    'Email' => ['message', 'mode'],
    'Uuid' => ['message', 'strict', 'versions'],
    'PositiveOrZero' => ['message'],
    'IdenticalTo' => ['value', 'message'],
  ];

  public function __construct(private readonly ?ConstraintManager $constraintManager = NULL) {}

  /**
   * Builds named inputs.
   *
   * @return array<string, \Drupal\tool\TypedData\InputDefinitionInterface>
   *   Native Tool API input definitions.
   */
  public function inputs(array $definitions): array {
    return $this->buildNamed($definitions, FALSE);
  }

  /**
   * Builds named outputs.
   *
   * @return array<string, \Drupal\tool\TypedData\OutputDefinitionInterface>
   *   Native Tool API output definitions.
   */
  public function outputs(array $definitions): array {
    return $this->buildNamed($definitions, TRUE);
  }

  /**
   * Builds one level of a named definition map.
   */
  private function buildNamed(array $definitions, bool $output, int $depth = 0): array {
    $built = [];
    foreach ($definitions as $name => $definition) {
      if (!is_string($name) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) || !is_array($definition)) {
        throw new \InvalidArgumentException('Tool definitions require named input or output mappings.');
      }
      $built[$name] = $this->build($name, $definition, $output, $depth);
    }
    return $built;
  }

  /**
   * Builds a primitive, map or list using the corresponding Tool API class.
   */
  private function build(string $name, array $config, bool $output, int $depth): InputDefinitionInterface|OutputDefinitionInterface {
    if ($depth > 32) {
      throw new \InvalidArgumentException('Tool definitions may nest at most 32 levels.');
    }
    $allowed = [
      'data_type', 'label', 'description', 'required', 'default_value',
      'constraints', 'properties', 'item_definition',
    ];
    if ($output) {
      $allowed[] = 'nullable';
    }
    if (array_diff(array_keys($config), $allowed)) {
      throw new \InvalidArgumentException(sprintf('Unsupported definition setting for "%s".', $name));
    }
    $type = $config['data_type'] ?? NULL;
    if (!in_array($type, ['string', 'integer', 'float', 'boolean', 'list', 'map', 'any'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unsupported JSON wire type for "%s".', $name));
    }
    foreach (['required', 'nullable'] as $flag) {
      if (array_key_exists($flag, $config) && !is_bool($config[$flag])) {
        throw new \InvalidArgumentException(sprintf('The "%s" flag for "%s" must be boolean.', $flag, $name));
      }
    }
    foreach (['label', 'description'] as $text) {
      if (isset($config[$text]) && !is_string($config[$text])) {
        throw new \InvalidArgumentException(sprintf('The "%s" setting for "%s" must be text.', $text, $name));
      }
    }
    if (isset($config['constraints']) && !is_array($config['constraints'])) {
      throw new \InvalidArgumentException(sprintf('Constraints for "%s" must be a mapping.', $name));
    }
    $this->validateConstraints($config['constraints'] ?? [], $name);
    if (array_key_exists('properties', $config) && $type !== 'map') {
      throw new \InvalidArgumentException(sprintf('Only a map definition can declare properties for "%s".', $name));
    }
    if (array_key_exists('item_definition', $config) && $type !== 'list') {
      throw new \InvalidArgumentException(sprintf('Only a list definition can declare an item for "%s".', $name));
    }

    $arguments = [
      'label' => $config['label'] ?? $name,
      'description' => $config['description'] ?? ($config['label'] ?? $name),
      'required' => $config['required'] ?? TRUE,
      'default_value' => $config['default_value'] ?? NULL,
      'constraints' => $config['constraints'] ?? [],
    ];
    $nullable = $config['nullable'] ?? FALSE;

    if ($type === 'map') {
      if (isset($config['properties']) && !is_array($config['properties'])) {
        throw new \InvalidArgumentException(sprintf('Properties for "%s" must be a mapping.', $name));
      }
      $arguments['property_definitions'] = $this->buildNamed($config['properties'] ?? [], $output, $depth + 1);
      return $output
        ? new MapOutputDefinition(...$arguments, nullable: $nullable)
        : new MapInputDefinition(...$arguments);
    }
    if ($type === 'list') {
      if (!isset($config['item_definition']) || !is_array($config['item_definition'])) {
        throw new \InvalidArgumentException(sprintf('A list definition for "%s" requires an item_definition.', $name));
      }
      $arguments['item_definition'] = $this->build($name . '_item', $config['item_definition'], $output, $depth + 1);
      return $output
        ? new ListOutputDefinition(...$arguments, nullable: $nullable)
        : new ListInputDefinition(...$arguments);
    }
    $arguments['data_type'] = $type;
    return $output
      ? new OutputDefinition(...$arguments, nullable: $nullable)
      : new InputDefinition(...$arguments);
  }

  /**
   * Rejects unsupported executable options and invalid native constructors.
   */
  private function validateConstraints(array $constraints, string $name): void {
    foreach ($constraints as $id => $options) {
      if (!is_string($id) || !isset(self::CONSTRAINT_OPTIONS[$id])) {
        throw new \InvalidArgumentException(sprintf('Unsupported external tool constraint on "%s".', $name));
      }
      if ($options !== NULL && (!is_array($options) || ($options !== [] && array_is_list($options)))) {
        throw new \InvalidArgumentException(sprintf('Options for constraint "%s" on "%s" must be a mapping or null.', $id, $name));
      }
      $options ??= [];
      if ($unknown = array_diff(array_keys($options), self::CONSTRAINT_OPTIONS[$id])) {
        throw new \InvalidArgumentException(sprintf('Unsupported option "%s" for constraint "%s" on "%s".', (string) reset($unknown), $id, $name));
      }
      if (in_array($id, ['Choice', 'AllowedValues'], TRUE) && !is_array($options['choices'] ?? NULL)) {
        throw new \InvalidArgumentException(sprintf('Constraint "%s" on "%s" requires explicit choices.', $id, $name));
      }
      if ($this->constraintManager === NULL) {
        throw new \LogicException('Building constrained definitions requires the native constraint manager.');
      }
      try {
        $this->constraintManager->create($id, $options);
      }
      catch (\Throwable $exception) {
        throw new \InvalidArgumentException(sprintf('Invalid options for constraint "%s" on "%s".', $id, $name), 0, $exception);
      }
    }
  }

}
