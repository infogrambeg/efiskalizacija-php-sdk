<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

use Efiskalizacija\DTO\InvoiceListResult;

final class InvoiceApi extends AbstractApi
{
    /**
     * Lista fiskalizovanih racuna.
     *
     * @throws \Efiskalizacija\Exception\EfiskalizacijaException
     */
    public function list(int $limit = 50, int $offset = 0): InvoiceListResult
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $response = $this->get('/invoice', [
            'limit' => (string) $limit,
            'offset' => (string) $offset,
        ]);

        return InvoiceListResult::fromArray($response['data'] ?? []);
    }
}
