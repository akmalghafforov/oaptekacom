<?php

namespace App\Services;

use RuntimeException;

class IcorpFailure extends RuntimeException
{
    public function __construct(public readonly string $stage, public readonly string $errorCode, public readonly ?int $httpStatus = null, public readonly string $reason = 'extraction')
    {
        parent::__construct('iCorp '.$stage.' failed: '.$errorCode.($httpStatus !== null ? ' (HTTP '.$httpStatus.')' : ''));
    }

    /** @return array{stage: string, code: string, http_status: ?int} */
    public function diagnostic(): array
    {
        return ['stage' => $this->stage, 'code' => $this->errorCode, 'http_status' => $this->httpStatus];
    }
}
