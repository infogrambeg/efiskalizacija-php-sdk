<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

final class Customer
{
    private function __construct(
        public readonly ?string $ime = null,
        public readonly ?string $pib = null,
        public readonly ?string $jmbg = null,
        public readonly ?string $jbkjs = null,
        public readonly ?string $adresa = null,
        public readonly ?string $mesto = null,
        public readonly ?string $email = null,
        public readonly ?string $telefon = null,
    ) {
    }

    /**
     * Pravno lice (identifikacija po PIB-u).
     * VSDC format: 10:PIB
     */
    public static function pravnoLice(
        string $pib,
        ?string $ime = null,
        ?string $adresa = null,
        ?string $mesto = null,
        ?string $email = null,
        ?string $telefon = null,
    ): self {
        if (!preg_match('/^\d{9}$/', $pib)) {
            throw new \InvalidArgumentException('PIB mora imati tacno 9 cifara');
        }

        return new self(
            ime: $ime,
            pib: $pib,
            adresa: $adresa,
            mesto: $mesto,
            email: $email,
            telefon: $telefon,
        );
    }

    /**
     * Fizicko lice (identifikacija po JMBG-u).
     * VSDC format: 11:JMBG
     */
    public static function fizickoLice(
        string $jmbg,
        ?string $ime = null,
        ?string $email = null,
        ?string $telefon = null,
    ): self {
        if (!preg_match('/^\d{13}$/', $jmbg)) {
            throw new \InvalidArgumentException('JMBG mora imati tacno 13 cifara');
        }

        return new self(
            ime: $ime,
            jmbg: $jmbg,
            email: $email,
            telefon: $telefon,
        );
    }

    /**
     * Javni sektor (identifikacija po JBKJS-u).
     * VSDC format: 12:JBKJS
     */
    public static function javniSektor(
        string $jbkjs,
        ?string $ime = null,
        ?string $email = null,
    ): self {
        return new self(
            ime: $ime,
            jbkjs: $jbkjs,
            email: $email,
        );
    }

    /**
     * Kupac bez identifikacije (anonimni kupac).
     */
    public static function anonimni(?string $email = null): self
    {
        return new self(email: $email);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->ime !== null) {
            $data['ime'] = $this->ime;
        }

        if ($this->pib !== null) {
            $data['tip'] = 'pravno_lice';
            $data['identifikator'] = $this->pib;
        } elseif ($this->jmbg !== null) {
            $data['tip'] = 'fizicko_lice';
            $data['identifikator'] = $this->jmbg;
        } elseif ($this->jbkjs !== null) {
            $data['tip'] = 'javni_sektor';
            $data['identifikator'] = $this->jbkjs;
        }

        if ($this->adresa !== null) {
            $data['adresa'] = $this->adresa;
        }
        if ($this->mesto !== null) {
            $data['mesto'] = $this->mesto;
        }
        if ($this->email !== null) {
            $data['email'] = $this->email;
        }
        if ($this->telefon !== null) {
            $data['telefon'] = $this->telefon;
        }

        return $data;
    }
}
