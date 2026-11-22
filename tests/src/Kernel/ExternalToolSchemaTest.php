<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\tool\Normalizer\ToolDefinitionSerializer;
use Drupal\tool\Tool\ToolInterface;
use Drupal\tool_external\Entity\ExternalTool;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The advertised native schema must describe the provider's enforced boundary.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class ExternalToolSchemaTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'serialization', 'tool', 'tool_external'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system', 'user', 'tool_external']);
    $this->installEntitySchema('user');
    $this->createUser();
    $this->setCurrentUser($this->createUser(['execute external tools']));
    $this->setSetting('tool_external', [
      'enabled' => TRUE,
      'allowed_hosts' => ['functions.example.test'],
      'secrets' => ['test' => 'synthetic-secret'],
    ]);
    $this->config('tool_external.settings')->set('connections.test', [
      'url' => 'https://functions.example.test/invoke',
      'secret' => 'test',
      'timeout' => 1.0,
    ])->save();
  }

  /**
   * Checks map and unrestricted types across serialized tool schemas.
   */
  public function testClosesMapsInsideListsAndNullableMapsWithoutClosingAny(): void {
    $map = [
      'data_type' => 'map',
      'properties' => [
        'label' => ['data_type' => 'string', 'constraints' => ['Length' => ['min' => 2]]],
        'children' => [
          'data_type' => 'list',
          'item_definition' => [
            'data_type' => 'map',
            'properties' => ['slug' => ['data_type' => 'string']],
          ],
        ],
        'empty' => ['data_type' => 'map', 'properties' => []],
        'metadata' => ['data_type' => 'any', 'required' => FALSE, 'default_value' => ['type' => 'object']],
      ],
    ];
    $this->registerTool(['payload' => $map + ['required' => FALSE]], ['answer' => $map + ['nullable' => TRUE]]);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('external:schema');
    $serializer = $this->container->get('tool.definition_serializer');
    $input = $serializer->normalizeInputSchema($tool);
    $output = $serializer->normalizeOutputSchema($tool);
    $this->assertFalse($input['additionalProperties']);
    $this->assertFalse($output['additionalProperties']);
    foreach ([$input['properties']['payload'], $output['properties']['answer']] as $schema) {
      $this->assertSame(['object', 'null'], $schema['type']);
      $this->assertFalse($schema['additionalProperties']);
      $this->assertFalse($schema['properties']['children']['items']['additionalProperties']);
      $this->assertFalse($schema['properties']['empty']['additionalProperties']);
      $this->assertSame(2, $schema['properties']['label']['minLength'], 'Native constraints remain present.');
      $this->assertArrayNotHasKey('additionalProperties', $schema['properties']['metadata'], 'Any remains unrestricted JSON data.');
      $this->assertSame(['type' => 'object'], $schema['properties']['metadata']['default'], 'Default data is never treated as a schema.');
    }
    $this->assertEquals($input['properties'], $serializer->normalizeInputDefinitions($tool));
    $this->assertEquals($output['properties'], $serializer->normalizeOutputDefinitions($tool));
    $this->assertEquals($input['properties']['payload'], $serializer->normalizeInputDefinition($tool, 'payload', $tool->getInputDefinition('payload')));
    $this->assertEquals($output['properties']['answer'], $serializer->normalizeOutputDefinition($tool, 'answer', $tool->getOutputDefinition('answer')));

    $valid = [
      'label' => 'ok',
      'children' => [['slug' => 'child']],
      'empty' => (object) [],
      'metadata' => ['anything' => ['arbitrary' => TRUE]],
    ];
    $this->assertSchemaAccepts($input, ['payload' => $valid]);
    $this->assertSchemaAccepts($output, ['answer' => $valid]);
    $this->assertSchemaAccepts($input, ['payload' => NULL]);
    $this->assertSchemaAccepts($output, ['answer' => NULL]);
    $this->assertSchemaAccepts($input, ['payload' => $valid + ['extra' => TRUE]], FALSE);
    $bad_nested = $valid;
    $bad_nested['children'][0]['extra'] = TRUE;
    $this->assertSchemaAccepts($output, ['answer' => $bad_nested], FALSE);
    $this->assertSchemaAccepts($output, ['answer' => $valid, 'extra' => TRUE], FALSE);
  }

  /**
   * Checks that invalid map keys can be corrected before dispatch.
   */
  public function testUnknownMapInputIsCorrectableAndSendsNoRequest(): void {
    $map = ['data_type' => 'map', 'properties' => ['label' => ['data_type' => 'string']]];
    $this->registerTool(['payload' => $map], ['answer' => $map]);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('external:schema');
    $input = ['payload' => ['label' => 'ok', 'extra' => 'unexpected']];
    $this->assertSchemaAccepts($this->container->get('tool.definition_serializer')->normalizeInputSchema($tool), $input, FALSE);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $this->container->set('http_client', $http);
    $this->container->set('tool_external.client', NULL);
    $result = $this->container->get('tool.runner')->run('external:schema', $input)->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame(FailureCategory::Input, $result->getFailureCategory());
    $this->assertTrue($result->getFailureCategory()->isCorrectable());
    $this->assertStringContainsString('undeclared map input', (string) $result->getMessage());
  }

  /**
   * Checks that undeclared outputs fail schema and execution validation.
   */
  public function testUnknownOutputIsRejectedBySchemaAndExecution(): void {
    $map = ['data_type' => 'map', 'properties' => ['label' => ['data_type' => 'string']]];
    $this->registerTool(['payload' => $map], ['answer' => $map]);
    $tool = $this->container->get('plugin.manager.tool')->createInstance('external:schema');
    $outputs = ['answer' => ['label' => 'ok', 'extra' => 'unexpected']];
    $this->assertSchemaAccepts($this->container->get('tool.definition_serializer')->normalizeOutputSchema($tool), $outputs, FALSE);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, array $options) use ($outputs): Response {
      $wire = json_decode($options['body'], TRUE, flags: JSON_THROW_ON_ERROR);
      return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'protocol' => 'drupal-external-tool/1',
        'invocation_id' => $wire['invocation_id'],
        'outputs' => $outputs,
      ], JSON_THROW_ON_ERROR));
    });
    $this->container->set('http_client', $http);
    $this->container->set('tool_external.client', NULL);
    $result = $this->container->get('tool.runner')->run('external:schema', ['payload' => ['label' => 'ok']])->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame(FailureCategory::Runtime, $result->getFailureCategory());
    $this->assertSame([], $result->getContextValues());
  }

  /**
   * Preserves native schema behavior for other providers.
   */
  public function testOtherProvidersKeepNativeOpenSchemas(): void {
    $factory = $this->container->get('tool_external.definition_factory');
    $map = ['data_type' => 'map', 'properties' => ['label' => ['data_type' => 'string']]];
    $other = $this->createMock(ToolInterface::class);
    $other->method('getPluginId')->willReturn('another_provider:example');
    $other->method('getInputDefinitions')->willReturn($factory->inputs(['payload' => $map]));
    $other->method('getOutputDefinitions')->willReturn($factory->outputs(['answer' => $map]));
    $native = new ToolDefinitionSerializer($this->container->get('serializer'));
    $decorated = $this->container->get('tool.definition_serializer');
    $this->assertSame($native->normalizeInputSchema($other), $decorated->normalizeInputSchema($other));
    $output = $decorated->normalizeOutputSchema($other);
    $this->assertSame($native->normalizeOutputSchema($other), $output);
    $this->assertArrayNotHasKey('additionalProperties', $output);
    $this->assertArrayNotHasKey('additionalProperties', $output['properties']['answer']);
    $this->assertSchemaAccepts($output, ['answer' => ['label' => 'ok', 'extra' => TRUE], 'extra' => TRUE]);
  }

  /**
   * Registers a tool with the supplied input and output contracts.
   */
  private function registerTool(array $inputs, array $outputs): void {
    ExternalTool::create([
      'id' => 'schema',
      'label' => 'Schema example',
      'description' => 'Verifies external wire schema compatibility.',
      'connection' => 'test',
      'inputs' => $inputs,
      'outputs' => $outputs,
    ])->save();
  }

  /**
   * Checks the native JSON Schema validation result for a candidate value.
   */
  private function assertSchemaAccepts(array $schema, array $value, bool $expected = TRUE): void {
    $validator = new Validator();
    $instance = json_decode(json_encode($value, JSON_THROW_ON_ERROR));
    $validator->validate(
      $instance,
      json_decode(json_encode($schema, JSON_THROW_ON_ERROR)),
    );
    $this->assertSame($expected, $validator->isValid(), json_encode($validator->getErrors(), JSON_THROW_ON_ERROR));
  }

}
