<?php

declare(strict_types=1);

namespace Efiskalizacija\Exception;

class EfiskalizacijaException extends \RuntimeException
{
    private ?string $responseBody;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous);
        $this->responseBody = $responseBody;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
