<?php

namespace App\Services;

class GmailException extends \RuntimeException
{
    public function __construct(string $message = 'Gmail временно недоступен. Попробуйте ещё раз.')
    {
        parent::__construct($message);
    }
}
