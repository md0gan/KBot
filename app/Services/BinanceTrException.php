<?php

namespace App\Services;

use RuntimeException;

class BinanceTrException extends RuntimeException
{
    public ?int $apiCode = null;

    public function __construct(string $message, ?int $apiCode = null)
    {
        parent::__construct($message);
        $this->apiCode = $apiCode;
    }
}
