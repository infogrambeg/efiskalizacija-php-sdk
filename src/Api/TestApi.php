<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

use Efiskalizacija\DTO\FiskalizacijaResult;

final class TestApi extends AbstractApi
{
    /**
     * Test fiskalizacija sa predefinisanim podacima (samo sandbox).
     *
     * @throws \Efiskalizacija\Exception\EfiskalizacijaException
     */
    public function run(): FiskalizacijaResult
    {
        $response = $this->post('/test');

        $data = $response['data'] ?? [];
        $rezultat = $data['rezultat'] ?? [];

        return FiskalizacijaResult::fromArray($rezultat);
    }
}
