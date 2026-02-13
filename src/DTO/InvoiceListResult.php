<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

final class InvoiceListResult
{
    /**
     * @param array<int, array<string, mixed>> $invoices
     */
    public function __construct(
        public readonly array $invoices,
        public readonly int $count,
        public readonly int $limit,
        public readonly int $offset,
    ) {
    }

    /**
     * @param array<string, mixed> $data API response "data" objekat
     */
    public static function fromArray(array $data): self
    {
        return new self(
            invoices: $data['invoices'] ?? [],
            count: (int) ($data['count'] ?? 0),
            limit: (int) ($data['limit'] ?? 50),
            offset: (int) ($data['offset'] ?? 0),
        );
    }
}
