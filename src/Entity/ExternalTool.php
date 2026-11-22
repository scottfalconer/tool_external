<?php

declare(strict_types=1);

namespace Drupal\tool_external\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool_external\Definition\DefinitionFactory;

/**
 * Stores a portable external tool definition without connection secrets.
 */
#[ConfigEntityType(
  id: 'external_tool',
  label: new TranslatableMarkup('External tool'),
  label_collection: new TranslatableMarkup('External tools'),
  config_prefix: 'tool',
  admin_permission: 'administer external tools',
  entity_keys: ['id' => 'id', 'label' => 'label', 'status' => 'status'],
  config_export: [
    'id', 'label', 'description', 'connection', 'contract_version',
    'operation', 'permission', 'inputs', 'outputs',
  ],
)]
final class ExternalTool extends ConfigEntityBase {

  /**
   * The stable machine name.
   *
   * @var string
   */
  protected $id;

  /**
   * The display label.
   *
   * @var string
   */
  protected $label;

  /**
   * The operation's description.
   */
  protected string $description = '';

  /**
   * The logical name of the connection.
   */
  protected string $connection = '';

  /**
   * The customer operation's contract version.
   */
  protected int $contract_version = 1;

  /**
   * A native ToolOperation value.
   */
  protected string $operation = 'transform';

  /**
   * An ordinary Drupal permission required by the Tool API runner.
   */
  protected string $permission = 'execute external tools';

  /**
   * Native Tool API input attributes serialized as configuration.
   */
  protected array $inputs = [];

  /**
   * Native Tool API output attributes serialized as configuration.
   */
  protected array $outputs = [];

  /**
   * Creates an unsaved registration from a function author's JSON declaration.
   *
   * The installing site supplies execution policy. Saving the new config
   * entity uses Drupal's normal collision checks and cache invalidation.
   */
  public static function fromDeclaration(string $json, string $connection, ToolOperation $operation, string $permission = 'execute external tools'): static {
    if (strlen($json) > 65536) {
      throw new \InvalidArgumentException('A tool declaration may contain at most 65536 bytes.');
    }
    $data = json_decode($json, TRUE, 64, JSON_THROW_ON_ERROR);
    $fields = ['id', 'label', 'description', 'contract_version', 'inputs', 'outputs'];
    if (!is_array($data) || array_diff_key($data, array_flip($fields)) || array_diff($fields, array_keys($data))) {
      throw new \InvalidArgumentException('A tool declaration must contain only the tool id, label, description, contract_version, inputs and outputs.');
    }
    $data += [
      'connection' => $connection,
      'operation' => $operation->value,
      'permission' => $permission,
      'status' => TRUE,
    ];
    self::validateDefinition($data);
    return static::create($data);
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    self::validateDefinition($this->toArray());
  }

  /**
   * Validates both saved entities and effective, overridden definitions.
   */
  public static function validateDefinition(array $data, ?DefinitionFactory $factory = NULL): void {
    foreach (['id', 'connection'] as $name) {
      if (!is_string($data[$name] ?? NULL) || !preg_match('/^[a-z][a-z0-9_]*$/D', $data[$name])) {
        throw new \InvalidArgumentException(sprintf('The external tool %s must be a machine name.', $name));
      }
    }
    foreach (['label', 'description', 'permission'] as $name) {
      if (!is_string($data[$name] ?? NULL) || trim($data[$name]) === '') {
        throw new \InvalidArgumentException(sprintf('The external tool %s must be nonempty text.', $name));
      }
    }
    if (str_contains($data['permission'], ',') && str_contains($data['permission'], '+')) {
      throw new \InvalidArgumentException('The external tool permission cannot mix comma and plus operators.');
    }
    if (!is_int($data['contract_version'] ?? NULL) || $data['contract_version'] < 1) {
      throw new \InvalidArgumentException('The external tool contract_version must be a positive integer.');
    }
    if (!is_string($data['operation'] ?? NULL) || ToolOperation::tryFrom($data['operation']) === NULL) {
      throw new \InvalidArgumentException('The external tool operation must be a Tool API operation.');
    }
    if (!is_bool($data['status'] ?? NULL)) {
      throw new \InvalidArgumentException('The external tool status must be boolean.');
    }
    if (!is_array($data['inputs'] ?? NULL) || !is_array($data['outputs'] ?? NULL)) {
      throw new \InvalidArgumentException('External tool inputs and outputs must be mappings.');
    }
    $factory ??= \Drupal::service('tool_external.definition_factory');
    $factory->inputs($data['inputs']);
    $factory->outputs($data['outputs']);
  }

}
