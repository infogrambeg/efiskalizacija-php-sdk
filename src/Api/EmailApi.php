<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

final class EmailApi extends AbstractApi
{
    /**
     * Posalji fiskalni racun na email kupcu.
     *
     * @param string $pfrBroj PFR broj racuna
     * @param string $email Email adresa primaoca
     * @return array<string, mixed> API response data
     *
     * @throws \Efiskalizacija\Exception\EfiskalizacijaException
     */
    public function send(string $pfrBroj, string $email): array
    {
        $response = $this->post('/send-email', [
            'pfr' => $pfrBroj,
            'email' => $email,
        ]);

        return $response['data'] ?? [];
    }
}
