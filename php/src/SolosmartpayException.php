<?php

namespace Solosmartpay;

use RuntimeException;

/** Raised for any non-2xx API response or transport failure. */
class SolosmartpayException extends RuntimeException
{
    /** @param array<string, list<string>> $errors field => messages (validation errors) */
    public function __construct(string $message, public readonly int $status = 0, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }
}
