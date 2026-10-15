<?php

namespace App\Services;

use Google\Service\Exception;

class GmailApiException extends Exception
{
    /** @param list<array<string,string>> $errors */
    public function __construct(int $status, array $errors, public readonly int $retryAfter = 0)
    {
        parent::__construct('Gmail API request failed', $status, null, $errors);
    }

    public function retryable(): bool
    {
        return in_array($this->getCode(), [429, 500, 502, 503, 504], true) || ($this->getCode() === 403 && (bool) array_intersect(array_column($this->getErrors(), 'reason'), ['rateLimitExceeded', 'userRateLimitExceeded', 'quotaExceeded', 'backendError']));
    }
}
