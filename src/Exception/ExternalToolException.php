<?php

declare(strict_types=1);

namespace Drupal\tool_external\Exception;

/**
 * A safe, stable failure code, without remote payloads or credentials.
 */
final class ExternalToolException extends \RuntimeException {

  public function __construct(private readonly string $failure, string $message) {
    parent::__construct($message);
  }

  /**
   * Returns the stable failure code for logs and result mapping.
   */
  public function failureCode(): string {
    return $this->failure;
  }

}
