<?php

declare(strict_types=1);

namespace Efiskalizacija\Exception;

/** HTTP 400/422 - Neispravni podaci u zahtevu. */
class ValidationException extends EfiskalizacijaException
{
    /** @var string[] */
    private array $errors;

    /**
     * @param string[] $errors
     */
    public function __construct(
        string $message = '',
        array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $responseBody = null,
    ) {
        parent::__construct($message, $code, $previous, $responseBody);
        $this->errors = $errors;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
