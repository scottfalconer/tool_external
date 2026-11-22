<?php

declare(strict_types=1);

namespace Drupal\tool_external\Transport;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\tool_external\Exception\ExternalToolException;

/**
 * Preserves JSON types at the boundary, using the native tool definitions.
 *
 * Tool API applies its own constraints and validates the result.
 */
final class JsonValues {

  /**
   * Encodes the already validated Tool API inputs without PHP objects.
   */
  public static function inputs(array $values, array $definitions): \stdClass {
    $wire = new \stdClass();
    foreach ($values as $name => $value) {
      if (!isset($definitions[$name])) {
        throw new ExternalToolException('input', 'An undeclared external tool input was provided.');
      }
      $wire->{$name} = self::toWire($value, $definitions[$name]);
    }
    return $wire;
  }

  /**
   * Rejects unknown outputs and wrong JSON types before Drupal can coerce them.
   */
  public static function outputs(\stdClass $values, array $definitions): array {
    $result = [];
    foreach (get_object_vars($values) as $name => $value) {
      if (!isset($definitions[$name])) {
        throw new ExternalToolException('output', 'The external tool returned an undeclared output.');
      }
      $result[$name] = self::fromWire($value, $definitions[$name]);
    }
    foreach ($definitions as $name => $definition) {
      if ($definition->isRequired() && !property_exists($values, $name)) {
        throw new ExternalToolException('output', 'The external tool omitted a required output.');
      }
    }
    return $result;
  }

  /**
   * Converts a typed Drupal input to its JSON representation.
   */
  private static function toWire(mixed $value, DataDefinitionInterface $definition): mixed {
    if ($definition->getDataType() === 'map' && is_array($value)) {
      $result = new \stdClass();
      $properties = $definition instanceof ComplexDataDefinitionInterface ? $definition->getPropertyDefinitions() : [];
      foreach ($value as $name => $child) {
        if (!isset($properties[$name])) {
          throw new ExternalToolException('input', 'An undeclared map input was provided.');
        }
        $result->{$name} = self::toWire($child, $properties[$name]);
      }
      return $result;
    }
    if ($definition instanceof ListDataDefinitionInterface && is_array($value)) {
      return array_map(fn ($child) => self::toWire($child, $definition->getItemDefinition()), $value);
    }
    return self::jsonSafe($value, FALSE);
  }

  /**
   * Reads a JSON output using its declared data type.
   */
  private static function fromWire(mixed $value, DataDefinitionInterface $definition): mixed {
    if ($value === NULL) {
      if (method_exists($definition, 'isNullable') && $definition->isNullable()) {
        return NULL;
      }
      throw new ExternalToolException('output', 'The external tool returned an unexpected null value.');
    }
    $type = $definition->getDataType();
    if ($type === 'any') {
      return self::jsonSafe($value, TRUE);
    }
    if ($type === 'map' && $definition instanceof ComplexDataDefinitionInterface && $value instanceof \stdClass) {
      return self::outputs($value, $definition->getPropertyDefinitions());
    }
    if ($type === 'list' && $definition instanceof ListDataDefinitionInterface && is_array($value) && array_is_list($value)) {
      return array_map(fn ($child) => self::fromWire($child, $definition->getItemDefinition()), $value);
    }
    $valid = match ($type) {
      'string' => is_string($value),
      'integer' => is_int($value),
      'float' => is_int($value) || (is_float($value) && is_finite($value)),
      'boolean' => is_bool($value),
      default => FALSE,
    };
    if (!$valid) {
      throw new ExternalToolException('output', 'The external tool returned a value with the wrong JSON type.');
    }
    return $value;
  }

  /**
   * Allows data only; never serializes arbitrary PHP objects or resources.
   */
  private static function jsonSafe(mixed $value, bool $from_json): mixed {
    if ($from_json && $value instanceof \stdClass) {
      $value = get_object_vars($value);
    }
    if (is_array($value)) {
      return array_map(fn ($child) => self::jsonSafe($child, $from_json), $value);
    }
    if ($value === NULL || is_string($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
      return $value;
    }
    throw new ExternalToolException($from_json ? 'output' : 'input', 'External tool values must contain JSON data only.');
  }

}
