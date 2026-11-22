<?php

declare(strict_types=1);

namespace Drupal\tool_external\Transport;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\tool_external\Config\StableConfigReader;
use Drupal\tool_external\Definition\DefinitionFactory;
use Drupal\tool_external\Exception\ExternalToolException;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * The single HTTPS transport for external Tool API implementations.
 */
final class ExternalClient {

  public const PROTOCOL = 'drupal-external-tool/1';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly StableConfigReader $config,
    private readonly ConnectionResolver $connections,
    private readonly DefinitionFactory $definitions,
    private readonly UuidInterface $uuid,
    private readonly AccountProxyInterface $currentUser,
    private readonly LoggerInterface $logger,
    private readonly ?TimeInterface $time = NULL,
  ) {}

  /**
   * Invokes a locally declared tool and returns its checked output values.
   *
   * An optional caller context carries an in-process initiating account and
   * caller label. Plain invocations use the current account for both roles.
   */
  public function invoke(string $id, array $inputs, array $caller_context = []): array {
    $invocation_id = $this->uuid->generate();
    $started = $this->now();
    $outcome = 'success';
    $deadline = NULL;
    $sink = NULL;
    try {
      $tool = $this->config->read('tool_external.tool.' . $id);
      if (($tool['status'] ?? FALSE) !== TRUE) {
        throw new ExternalToolException('disabled', 'This external tool is not enabled.');
      }
      $connection = $this->connections->resolve((string) ($tool['connection'] ?? ''), 'external:' . $id);
      $deadline = $started + $connection['timeout'];
      $executor = [
        'id' => (string) $this->currentUser->id(),
        'roles' => array_values($this->currentUser->getRoles()),
      ];
      $actor = $caller_context === [] ? $this->currentUser : ($caller_context['actor'] ?? NULL);
      $caller = $caller_context['caller'] ?? 'tool_api';
      if (!$actor instanceof AccountInterface || !is_string($caller)) {
        throw new ExternalToolException('context', 'The Drupal invocation context is invalid.');
      }
      $request = [
        'protocol' => self::PROTOCOL,
        'tool' => ['id' => 'external:' . $id, 'version' => (int) ($tool['contract_version'] ?? 0)],
        'invocation_id' => $invocation_id,
        'context' => [
          'site_id' => (string) ($this->config->read('system.site')['uuid'] ?? ''),
          'actor' => ['id' => (string) $actor->id(), 'roles' => array_values($actor->getRoles())],
          'executor' => $executor,
          'caller' => $caller,
        ],
        'inputs' => JsonValues::inputs($inputs, $this->definitions->inputs($tool['inputs'] ?? [])),
      ];
      $body = json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
      if (strlen($body) > $connection['max_request_bytes']) {
        throw new ExternalToolException('request_size', 'The external tool request exceeds the platform limit.');
      }
      if (!extension_loaded('curl')) {
        throw new ExternalToolException('configuration', 'External tools require the PHP cURL extension.');
      }
      $remaining = $deadline - $this->now();
      // Guzzle converts seconds to integer cURL milliseconds. A positive
      // fraction below 1 ms becomes zero, which disables cURL's timeout.
      // ConnectionResolver bounds the value before this conversion; flooring
      // the residual budget never extends the invocation's deadline.
      $timeout_ms = (int) floor($remaining * 1000);
      if ($timeout_ms < 1) {
        throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
      }
      $network_timeout = $timeout_ms / 1000;
      $sink = new BoundedResponseStream($connection['max_response_bytes']);
      $response = $this->httpClient->request('POST', $connection['url'], [
        'headers' => [
          'Accept' => 'application/json',
          'Accept-Encoding' => 'identity',
          'Content-Type' => 'application/json',
          'Authorization' => 'Bearer ' . $connection['secret'],
          'X-Invocation-Id' => $invocation_id,
        ],
        'body' => $body,
        // The supported platform uses Guzzle's cURL handler, preserving the
        // Drupal client's middleware while enforcing one total network timeout.
        'timeout' => $network_timeout,
        'connect_timeout' => min(1.0, $network_timeout),
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'verify' => TRUE,
        'decode_content' => FALSE,
        'stream' => FALSE,
        'sink' => $sink,
      ]);
      $stream = $response->getBody();
      try {
        // cURL can receive proxy CONNECT or 1xx headers before the endpoint
        // replies. The bounded sink limits writes throughout the transfer.
        $this->checkResponse($response, $connection['max_response_bytes'], $deadline);
        if ($stream->isSeekable()) {
          $stream->rewind();
        }
        $raw = '';
        $at_end = $stream->eof();
        while (!$at_end) {
          $remaining = $deadline - $this->now();
          if ($remaining <= 0) {
            throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
          }
          $chunk = $stream->read(min(8192, $connection['max_response_bytes'] + 1 - strlen($raw)));
          // A final read can both block and reach EOF. Check before accepting
          // its bytes, not only at the start of the next loop iteration.
          if ($this->now() >= $deadline || $stream->getMetadata('timed_out')) {
            throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
          }
          $at_end = $stream->eof();
          if ($chunk === '' && !$at_end) {
            throw new ExternalToolException('response_read', 'The external tool response could not be read.');
          }
          $raw .= $chunk;
          if (strlen($raw) > $connection['max_response_bytes']) {
            throw new ExternalToolException('response_size', 'The external tool response exceeds the platform limit.');
          }
        }
      }
      finally {
        $stream->close();
      }
      if ($this->now() >= $deadline) {
        throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
      }
      $decoded = json_decode($raw, FALSE, 64, JSON_THROW_ON_ERROR);
      if (!$decoded instanceof \stdClass
        || ($decoded->protocol ?? NULL) !== self::PROTOCOL
        || ($decoded->invocation_id ?? NULL) !== $invocation_id
        || !($decoded->outputs ?? NULL) instanceof \stdClass
        || array_diff(array_keys(get_object_vars($decoded)), ['protocol', 'invocation_id', 'outputs'])) {
        throw new ExternalToolException('protocol', 'The external tool response does not match the invocation contract.');
      }
      $outputs = JsonValues::outputs($decoded->outputs, $this->definitions->outputs($tool['outputs'] ?? []));
      if ($this->now() >= $deadline) {
        throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
      }
      return $outputs;
    }
    catch (ExternalToolException $e) {
      $outcome = $e->failureCode();
      throw $e;
    }
    catch (\JsonException) {
      $outcome = 'json';
      throw new ExternalToolException('json', 'The external tool exchange contains invalid JSON.');
    }
    catch (\Throwable $exception) {
      // Guzzle wraps callback failures. Unwrap only our safe public exception;
      // HTTP exceptions may contain Authorization headers or response data.
      $timed_out = $deadline !== NULL && $this->now() >= $deadline;
      for ($cause = $exception; $cause !== NULL; $cause = $cause->getPrevious()) {
        if ($cause instanceof ExternalToolException) {
          $outcome = $cause->failureCode();
          throw $cause;
        }
        if ($cause instanceof ConnectException || $cause instanceof RequestException) {
          $timed_out = $timed_out || ($cause->getHandlerContext()['errno'] ?? NULL) === CURLE_OPERATION_TIMEDOUT;
        }
      }
      if ($timed_out) {
        $outcome = 'timeout';
        throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
      }
      $outcome = 'transport';
      throw new ExternalToolException('transport', 'The external tool request could not be completed.');
    }
    finally {
      if ($sink !== NULL) {
        $sink->close();
      }
      $this->logger->notice('External tool {tool}, invocation {invocation}, outcome {outcome}, duration {duration} ms.', [
        'tool' => 'external:' . $id,
        'invocation' => $invocation_id,
        'outcome' => $outcome,
        'duration' => (int) round(($this->now() - $started) * 1000),
      ]);
    }
  }

  /**
   * Checks the final HTTP response before decoding the tool result.
   */
  private function checkResponse(ResponseInterface $response, int $maximum_bytes, float $deadline): void {
    if ($this->now() >= $deadline) {
      throw new ExternalToolException('timeout', 'The external tool exceeded its deadline.');
    }
    if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
      throw new ExternalToolException('http_status', 'The external tool endpoint returned an unsuccessful HTTP status.');
    }
    $content_type = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
    if ($content_type !== 'application/json') {
      throw new ExternalToolException('content_type', 'The external tool endpoint did not return JSON.');
    }
    $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
    if ($encoding !== '' && $encoding !== 'identity') {
      throw new ExternalToolException('content_encoding', 'The external tool endpoint must return uncompressed JSON.');
    }
    $length = $response->getHeaderLine('Content-Length');
    if ($length !== '' && (!ctype_digit($length) || (float) $length > $maximum_bytes)) {
      throw new ExternalToolException('response_size', 'The external tool response exceeds the platform limit.');
    }
  }

  /**
   * Reads Drupal's current clock, independent of the request start time.
   */
  private function now(): float {
    return $this->time?->getCurrentMicroTime() ?? microtime(TRUE);
  }

}
