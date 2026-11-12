<?php

namespace App\Services;

use RuntimeException;

class ExternalServiceUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $retryAfter = null)
    {
        parent::__construct($reason);
    }
}
