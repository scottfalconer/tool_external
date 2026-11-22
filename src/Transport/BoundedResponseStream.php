<?php

declare(strict_types=1);

namespace Drupal\tool_external\Transport;

use Drupal\tool_external\Exception\ExternalToolException;
use GuzzleHttp\Psr7\Stream;

/**
 * Caps received bytes while the HTTP handler is downloading the response.
 */
final class BoundedResponseStream extends Stream {

  /**
   * Number of bytes written to the underlying stream.
   */
  private int $receivedBytes = 0;

  public function __construct(private readonly int $maximumBytes) {
    $resource = fopen('php://temp/maxmemory:65536', 'w+');
    if ($resource === FALSE) {
      throw new ExternalToolException('response_read', 'The external tool response buffer could not be opened.');
    }
    parent::__construct($resource);
  }

  /**
   * Rejects overflow before bytes reach the memory or temporary-file buffer.
   */
  public function write($string): int {
    if ($this->receivedBytes + strlen($string) > $this->maximumBytes) {
      throw new ExternalToolException('response_size', 'The external tool response exceeds the platform limit.');
    }
    $written = parent::write($string);
    $this->receivedBytes += $written;
    return $written;
  }

}
