<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request that is well-formed but not allowed in the current state (409 by default).
 * `$errorCode` is the stable machine-readable code clients can rely on; `$detail` adds fields to the JSON body.
 */
class BusinessRuleException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        /** @var array<string, mixed> extra machine-readable detail returned next to the message (e.g. who scanned a ticket) */
        public readonly array $detail = [],
    ) {
        parent::__construct($message);
    }
}
