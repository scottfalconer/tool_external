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

  /**
   * Whether a write was refused because it would exceed the byte limit.
   */
  private bool $overflowed = FALSE;

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
    if ($this->overflowed || $this->receivedBytes + strlen($string) > $this->maximumBytes) {
      // A short write stops cURL and preserves the response on its exception.
      $this->overflowed = TRUE;
      return 0;
    }
    $written = parent::write($string);
    $this->receivedBytes += $written;
    return $written;
  }

  /**
   * Reports whether this response exceeded the configured buffer.
   */
  public function overflowed(): bool {
    return $this->overflowed;
  }

}
