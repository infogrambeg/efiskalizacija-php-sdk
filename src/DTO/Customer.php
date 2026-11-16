<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

final class Customer
{
    /** Oblik `PIB:JBKJS` (PIB tačno 9 cifara, JBKJS tačno 5) koji ide pod ključem `jbkjs`. */
    private const PIB_JBKJS = '/^\d{9}:\d{5}\z/';

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
     * VSDC format: 10:PIB. `trim()` pre provere skida razmak, tab, novi red,
     * CR, NUL i vertikalni tab na krajevima (ne form feed ni tvrdi razmak), i
     * šalje se očišćen PIB. Od vrednosti sa belinama 1.1.1 je primao samo
     * `"123456789\n"` (`$` bez `D`) i slao je sa novim redom.
     */
    public static function pravnoLice(
        string $pib,
        ?string $ime = null,
        ?string $adresa = null,
        ?string $mesto = null,
        ?string $email = null,
        ?string $telefon = null,
    ): self {
        $pib = trim($pib);
        if (preg_match('/^\d{9}\z/', $pib) !== 1) {
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
     * VSDC format: 11:JMBG. Beline na krajevima se uklanjaju kao kod PIB-a
     * (`trim()`: razmak, tab, novi red, CR, NUL, vertikalni tab).
     */
    public static function fizickoLice(
        string $jmbg,
        ?string $ime = null,
        ?string $email = null,
        ?string $telefon = null,
    ): self {
        $jmbg = trim($jmbg);
        if (preg_match('/^\d{13}\z/', $jmbg) !== 1) {
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
     * Javni sektor.
     *
     * Sa `$pib`: Poreska uprava za šifru 12 traži PIB i JBKJS kupca u obliku
     * `12:PIB:JBKJS`, npr. `12:123456789:54321` (tehničko uputstvo ESIR/L-PFR,
     * odeljak 9.8). Server oznaci dodaje samo `12:`, pa SDK šalje
     * `jbkjs: "PIB:JBKJS"`; svojstvo `$jbkjs` nosi taj spojen oblik, a `$pib`
     * ostaje `null`. PIB mora imati tačno 9 cifara, a JBKJS tačno 5, bez
     * belina i drugih znakova, inače se baca `InvalidArgumentException` pre
     * slanja.
     *
     * Bez `$pib` kupac je isti kao u 1.1.1: svojstvo `$jbkjs` nosi vrednost
     * bez izmene i bez provere, a `toArray()` šalje `tip: "javni_sektor"` i
     * `identifikator`, pa server takav račun izdaje ili odbija kao do sada.
     * Jedini izuzetak je vrednost tačno u obliku `PIB:JBKJS`
     * (`javniSektor('123456789:54321')`): ona se šalje kao `jbkjs`.
     *
     * `$pib` je dodat u 1.2.0 na kraju, pa postojeći pozivi ostaju ispravni u
     * obliku.
     *
     * @throws \InvalidArgumentException
     */
    public static function javniSektor(
        string $jbkjs,
        ?string $ime = null,
        ?string $email = null,
        ?string $pib = null,
    ): self {
        if ($pib === null) {
            return new self(ime: $ime, jbkjs: $jbkjs, email: $email);
        }

        if (preg_match('/^\d{9}\z/', $pib) !== 1 || preg_match('/^\d{5}\z/', $jbkjs) !== 1) {
            throw new \InvalidArgumentException(
                'Za javni sektor Poreska uprava trazi PIB i JBKJS kupca: '
                . "PIB tacno 9 cifara, JBKJS tacno 5 cifara (javniSektor('54321', pib: '123456789'))"
            );
        }

        return new self(ime: $ime, jbkjs: $pib . ':' . $jbkjs, email: $email);
    }

    /**
     * Kupac bez identifikacije (anonimni kupac).
     */
    public static function anonimni(?string $email = null): self
    {
        return new self(email: $email);
    }

    /**
     * Kupac u obliku koji server čita: identifikacija pod `pib`, `jmbg` ili
     * `jbkjs` (server od nje gradi oznaku kupca `10:PIB`, `11:JMBG` ili
     * `12:PIB:JBKJS`, a PIB i JMBG čuva uz račun), uz `ime`, `adresa`, `email`
     * i `telefon`, koje server čuva. `mesto` se šalje, ali ga server ne čuva.
     *
     * JBKJS koji nije u obliku `PIB:JBKJS` (`javniSektor()` bez `$pib`, i
     * objekat iz `unserialize()` serijala 1.1.1) šalje se kao u 1.1.1:
     * `tip: "javni_sektor"` + `identifikator`.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->ime !== null) {
            $data['ime'] = $this->ime;
        }

        if ($this->pib !== null) {
            $data['pib'] = $this->pib;
        } elseif ($this->jmbg !== null) {
            $data['jmbg'] = $this->jmbg;
        } elseif ($this->jbkjs !== null && preg_match(self::PIB_JBKJS, $this->jbkjs) === 1) {
            $data['jbkjs'] = $this->jbkjs;
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
