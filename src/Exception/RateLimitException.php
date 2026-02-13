<?php

declare(strict_types=1);

namespace Efiskalizacija\Exception;

/** HTTP 429 - Previse zahteva. */
class RateLimitException extends EfiskalizacijaException
{
    private ?int $retryAfter;

    public function __construct(
        string $message = '',
        ?int $retryAfter = null,
        int $code = 429,
        ?\Throwable $previous = null,
        ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous, $responseBody);
        $this->retryAfter = $retryAfter;
    }

    /** Broj sekundi do sledeceg dozvoljenog zahteva. */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
