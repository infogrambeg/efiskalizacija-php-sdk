<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

use Efiskalizacija\DTO\FiskalizacijaResult;
use Efiskalizacija\DTO\Invoice;

final class FiskalizacijaApi extends AbstractApi
{
    /**
     * Fiskalizuj racun.
     *
     * @throws \Efiskalizacija\Exception\EfiskalizacijaException
     */
    public function fiskalizuj(Invoice $invoice): FiskalizacijaResult
    {
        $response = $this->post('/fiskalizacija', $invoice->toArray());

        $data = $response['data'] ?? [];
        $rezultat = $data['rezultat'] ?? [];

        return FiskalizacijaResult::fromArray($rezultat);
    }
}
