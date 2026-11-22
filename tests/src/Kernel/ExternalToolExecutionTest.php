<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\FailureCategory;
use Drupal\tool_external\Entity\ExternalTool;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Runs real Tool API calls through the provider and the actual HTTP adapter.
 */
#[Group('tool_external')]
#[RunTestsInSeparateProcesses]
final class ExternalToolExecutionTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'tool', 'tool_external'];

  /**
   * Requests observed by the fixture HTTP transport.
   */
  private array $requests = [];

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
      'allowed_hosts' => ['functions.example.com'],
      'secrets' => ['demo' => 'test-only'],
    ]);
    $this->config('tool_external.settings')->set('connections.demo', [
      'url' => 'https://functions.example.com/invoke',
      'secret' => 'demo',
      'timeout' => 2.0,
    ])->save();
    ExternalTool::create([
      'id' => 'prepare',
      'label' => 'Prepare',
      'description' => 'Prepares typed campaign data.',
      'connection' => 'demo',
      'inputs' => ['title' => ['data_type' => 'string']],
      'outputs' => [
        'counter' => ['data_type' => 'integer'],
        'enabled' => ['data_type' => 'boolean'],
        'items' => ['data_type' => 'list', 'item_definition' => ['data_type' => 'string']],
        'optional_note' => ['data_type' => 'string', 'nullable' => TRUE],
        'details' => [
          'data_type' => 'map',
          'properties' => [
            'label' => ['data_type' => 'string', 'constraints' => ['Length' => ['min' => 3]]],
          ],
        ],
      ],
    ])->save();
  }

  /**
   * False, zero, empty lists and explicitly nullable values survive the runner.
   */
  public function testTypedResultsAndTrustedCallerContext(): void {
    $this->respondWith($this->validOutputs());
    $actor = $this->createUser();
    $executor = (string) $this->container->get('current_user')->id();
    $invocation = $this->container->get('tool.runner')->run(
      'external:prepare',
      ['title' => 'Campaign'],
      ['tool_external_context' => ['actor' => $actor, 'caller' => 'content.prepare.v1']],
    );
    $this->assertTrue($invocation->getResult()->isSuccess(), (string) $invocation->getResult()->getMessage());
    $this->assertSame($this->validOutputs(), $invocation->getResult()->getContextValues());
    $this->assertSame($this->validOutputs(), $invocation->getFormattedResult()->getContextValues());
    $this->assertCount(1, $this->requests);
    $wire = json_decode((string) $this->requests[0]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(['title' => 'Campaign'], $wire['inputs']);
    $this->assertSame((string) $actor->id(), $wire['context']['actor']['id']);
    $this->assertSame($actor->getRoles(), $wire['context']['actor']['roles']);
    $this->assertSame($executor, $wire['context']['executor']['id']);
    $this->assertSame('content.prepare.v1', $wire['context']['caller']);
    $this->assertSame('Bearer test-only', $this->requests[0]->getHeaderLine('Authorization'));
  }

  /**
   * Direct invocations use Drupal's current account for actor and executor.
   */
  public function testDefaultAccountContext(): void {
    $this->respondWith($this->validOutputs());
    $result = $this->container->get('tool.runner')->run('external:prepare', ['title' => 'Campaign'])->getResult();
    $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
    $wire = json_decode((string) $this->requests[0]->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
    $this->assertSame((string) $this->container->get('current_user')->id(), $wire['context']['actor']['id']);
    $this->assertSame($wire['context']['executor'], $wire['context']['actor']);
    $this->assertSame('tool_api', $wire['context']['caller']);
  }

  /**
   * Supplying custom provenance requires a Drupal account object.
   */
  public function testInvalidContextFailsBeforeHttp(): void {
    $this->respondWith($this->validOutputs());
    $runner = $this->container->get('tool.runner');
    foreach (['invalid', ['caller' => 'custom'], ['actor' => 'invalid']] as $context) {
      $result = $runner->run('external:prepare', ['title' => 'Campaign'], ['tool_external_context' => $context])->getResult();
      $this->assertFalse($result->isSuccess());
    }
    $this->assertCount(0, $this->requests);
  }

  /**
   * Wrong shape cannot become a successful result through Drupal coercion.
   */
  #[DataProvider('invalidOutputs')]
  public function testInvalidOutputsAreFailedResults(string $case): void {
    $outputs = $this->validOutputs();
    switch ($case) {
      case 'missing':
        unset($outputs['counter']);
        break;

      case 'wrong_type':
        $outputs['counter'] = '0';
        break;

      case 'extra':
        $outputs['unexpected'] = TRUE;
        break;

      case 'nested_extra':
        $outputs['details']['unexpected'] = TRUE;
        break;

      case 'list_as_object':
        $outputs['items'] = (object) ['first' => 'value'];
        break;

      case 'map_as_list':
        $outputs['details'] = [];
        break;

      case 'null':
        $outputs['counter'] = NULL;
        break;

      case 'native_constraint':
        $outputs['details']['label'] = 'x';
        break;
    }
    $this->respondWith($outputs);
    $result = $this->container->get('tool.runner')->run('external:prepare', ['title' => 'Campaign'])->getResult();
    $this->assertFalse($result->isSuccess(), $case);
    $this->assertSame(FailureCategory::Runtime, $result->getFailureCategory());
    $this->assertSame([], $result->getContextValues(), 'Failed output validation exposes no partial values.');
  }

  /**
   * Provides cases with outputs that violate the local contract.
   */
  public static function invalidOutputs(): array {
    $cases = [
      'missing', 'wrong_type', 'extra', 'nested_extra',
      'list_as_object', 'map_as_list', 'null', 'native_constraint',
    ];
    return array_combine($cases, array_map(static fn (string $case) => [$case], $cases));
  }

  /**
   * The runner rejects missing or undeclared input before any HTTP call.
   */
  public function testInputErrorsDoNotSendRequests(): void {
    $this->respondWith($this->validOutputs());
    $result = $this->container->get('tool.runner')->run('external:prepare')->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame(FailureCategory::Input, $result->getFailureCategory());
    $this->assertSame([], $this->requests);
    $inputs = ['title' => 'Campaign', 'unexpected' => TRUE];
    $result = $this->container->get('tool.runner')->run('external:prepare', $inputs)->getResult();
    $this->assertFalse($result->isSuccess());
    $this->assertSame(FailureCategory::Input, $result->getFailureCategory());
    $this->assertSame([], $this->requests);
  }

  /**
   * Returns successful fixture outputs for the external tool.
   */
  private function validOutputs(): array {
    return [
      'counter' => 0,
      'enabled' => FALSE,
      'items' => [],
      'optional_note' => NULL,
      'details' => ['label' => 'Ready'],
    ];
  }

  /**
   * Configures the mock HTTP transport to return the supplied outputs.
   */
  private function respondWith(array $outputs): void {
    $mock = new MockHandler([
      function (RequestInterface $request) use ($outputs): Response {
        $this->requests[] = $request;
        $body = json_decode((string) $request->getBody(), TRUE, flags: JSON_THROW_ON_ERROR);
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
          'protocol' => 'drupal-external-tool/1',
          'invocation_id' => $body['invocation_id'],
          'outputs' => (object) $outputs,
        ], JSON_THROW_ON_ERROR));
      },
    ]);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($mock)]));
  }

}
