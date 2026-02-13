<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

use Efiskalizacija\DTO\TenantStatus;

final class StatusApi extends AbstractApi
{
    /**
     * Informacije o tenantu i statistike fiskalizacije.
     *
     * @throws \Efiskalizacija\Exception\EfiskalizacijaException
     */
    public function fetch(): TenantStatus
    {
        $response = $this->get('/status');

        return TenantStatus::fromArray($response['data'] ?? []);
    }
}
