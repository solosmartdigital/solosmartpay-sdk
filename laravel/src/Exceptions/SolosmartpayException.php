<?php

namespace SolosmartDigital\Solosmartpay\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/** Raised for any non-2xx API response, a transport failure, or missing configuration. */
class SolosmartpayException extends RuntimeException
{
    /** @param array<string, list<string>> $errors field => messages (validation errors) */
    public function __construct(string $message, public readonly int $status = 0, public readonly array $errors = [])
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $json = $response->json();
        $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'HTTP '.$response->status();
        $errors = is_array($json) && is_array($json['errors'] ?? null) ? $json['errors'] : [];

        return new self($message, $response->status(), $errors);
    }

    public function isValidation(): bool
    {
        return $this->status === 422;
    }

    /** 409: reference already used for another invoice, or Idempotency-Key reused with a different request. */
    public function isConflict(): bool
    {
        return $this->status === 409;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401 || $this->status === 403;
    }
}
