<?php

declare(strict_types=1);

namespace Drupal\Tests\tool_external\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Site\Settings;
use Drupal\Tests\UnitTestCase;
use Drupal\tool_external\Config\StableConfigReader;
use Drupal\tool_external\Definition\DefinitionFactory;
use Drupal\tool_external\Exception\ExternalToolException;
use Drupal\tool_external\Transport\BoundedResponseStream;
use Drupal\tool_external\Transport\ConnectionResolver;
use Drupal\tool_external\Transport\ExternalClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;

/**
 * Tests bounded response acceptance and the fixed transport contract.
 */
#[Group('tool_external')]
final class ExternalClientDeadlineTest extends UnitTestCase {

  /**
   * A read that reaches EOF after the deadline cannot report success.
   */
  public function testRejectsLateFinalChunk(): void {
    $body = new class(fopen('php://temp', 'w+')) extends Stream {

      /**
       * Whether a body read started.
       */
      public bool $didRead = FALSE;

      /**
       * Whether the fixture finished reading its body.
       */
      private bool $finished = FALSE;

      /**
       * {@inheritdoc}
       */
      public function read($length): string {
        $this->didRead = TRUE;
        usleep(100000);
        $chunk = parent::read($length);
        $this->finished = TRUE;
        return $chunk;
      }

      /**
       * {@inheritdoc}
       */
      public function eof(): bool {
        return $this->finished;
      }

    };
    $body->write($this->responseBody());
    $body->rewind();
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturn(
      new Response(200, ['Content-Type' => 'application/json'], $body),
    );

    try {
      $this->client($http, 0.05)->invoke('probe', []);
      $this->fail('A late final chunk must not be accepted.');
    }
    catch (ExternalToolException $exception) {
      $this->assertSame('timeout', $exception->failureCode());
    }
    $this->assertTrue($body->didRead, 'The last blocking read was exercised.');
    $this->assertFalse($body->isReadable(), 'The body is closed after failure.');
  }

  /**
   * One invocation issues one request with no redirect or decoding behavior.
   */
  public function testUsesFixedTransportOptions(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturnCallback(
      function ($method, $url, array $options) {
        $this->assertSame('POST', $method);
        $this->assertSame('https://functions.example.test/invoke', $url);
        $this->assertFalse($options['allow_redirects']);
        $this->assertFalse($options['decode_content']);
        $this->assertFalse($options['http_errors']);
        $this->assertTrue($options['verify']);
        $this->assertFalse($options['stream']);
        $this->assertInstanceOf(BoundedResponseStream::class, $options['sink']);
        $this->assertSame('identity', $options['headers']['Accept-Encoding']);
        $this->assertSame('Bearer synthetic-secret', $options['headers']['Authorization']);
        $this->assertGreaterThan(0, $options['timeout']);
        $this->assertLessThanOrEqual(1.0, $options['timeout']);
        $this->assertGreaterThanOrEqual(1, (int) ($options['timeout'] * 1000), 'The cURL conversion cannot produce zero.');
        return new Response(200, ['Content-Type' => 'application/json'], $this->responseBody());
      },
    );
    $this->assertSame(['ok' => TRUE], $this->client($http)->invoke('probe', []));
  }

  /**
   * Even a valid configured timeout can leave less than one representable tick.
   */
  public function testRejectsSubmillisecondResidualBeforeHttp(): void {
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentMicroTime')->willReturnOnConsecutiveCalls(1000.0, 1000.0025, 1000.0025);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    try {
      $this->client($http, 0.003, $clock)->invoke('probe', []);
      $this->fail('A residual 0.5 ms cannot be represented as a positive cURL timeout.');
    }
    catch (ExternalToolException $exception) {
      $this->assertSame('timeout', $exception->failureCode());
    }
  }

  /**
   * The smallest representable budget reaches Guzzle as a positive millisecond.
   */
  public function testPreservesPositiveCurlMillisecondBudget(): void {
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getCurrentMicroTime')->willReturn(0.0);
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturnCallback(function ($method, $url, array $options) {
      $this->assertSame(1, (int) ($options['timeout'] * 1000));
      $this->assertSame(1, (int) ($options['connect_timeout'] * 1000));
      return new Response(200, ['Content-Type' => 'application/json'], $this->responseBody());
    });
    $this->assertSame(['ok' => TRUE], $this->client($http, 0.001, $clock)->invoke('probe', []));
  }

  /**
   * Compressed bodies cannot hide the socket behind a decoder stream.
   */
  public function testRejectsCompressedResponses(): void {
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturn(
      new Response(200, ['Content-Type' => 'application/json', 'Content-Encoding' => 'gzip'], $this->responseBody()),
    );
    try {
      $this->client($http)->invoke('probe', []);
      $this->fail('The v1 protocol requires uncompressed JSON.');
    }
    catch (ExternalToolException $exception) {
      $this->assertSame('content_encoding', $exception->failureCode());
    }
  }

  /**
   * Builds a client with the fixture connection and supplied HTTP transport.
   */
  private function client(ClientInterface $http, float $timeout = 1.0, ?TimeInterface $clock = NULL): ExternalClient {
    $config = $this->getConfigFactoryStub([
      'tool_external.tool.probe' => [
        'status' => TRUE,
        'connection' => 'test',
        'contract_version' => 1,
        'inputs' => [],
        'outputs' => ['ok' => ['data_type' => 'boolean']],
      ],
      'tool_external.settings' => [
        'connections' => [
          'test' => [
            'url' => 'https://functions.example.test/invoke',
            'secret' => 'test',
            'timeout' => $timeout,
          ],
        ],
      ],
      'system.site' => ['uuid' => 'synthetic-site'],
    ]);
    $settings = new Settings([
      'tool_external' => [
        'enabled' => TRUE,
        'allowed_hosts' => ['functions.example.test'],
        'secrets' => ['test' => 'synthetic-secret'],
      ],
    ]);
    $account = $this->createMock(AccountProxyInterface::class);
    $account->method('id')->willReturn(42);
    $account->method('getRoles')->willReturn(['authenticated']);
    $uuid = $this->createMock(UuidInterface::class);
    $uuid->method('generate')->willReturn('synthetic-invocation');
    $reader = new StableConfigReader($config);
    return new ExternalClient($http, $reader, new ConnectionResolver($reader, $settings), new DefinitionFactory(), $uuid, $account, new NullLogger(), $clock);
  }

  /**
   * Returns a valid serialized HTTP response body.
   */
  private function responseBody(): string {
    return json_encode([
      'protocol' => ExternalClient::PROTOCOL,
      'invocation_id' => 'synthetic-invocation',
      'outputs' => ['ok' => TRUE],
    ], JSON_THROW_ON_ERROR);
  }

}
