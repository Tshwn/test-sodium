<?php

namespace App\Exceptions;

use Exception;

class OidcVerificationException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $status = 401,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }
}
