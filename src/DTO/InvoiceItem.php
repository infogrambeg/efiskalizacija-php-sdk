<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

use Efiskalizacija\Enum\TaxCategory;

final class InvoiceItem
{
    /**
     * Najveća dužina šifre artikla u znakovima koju server prima: prati
     * granicu servera za `stavke[].sifra_artikla` (`InvoiceValidator`, kolona
     * `stavke_racuna.sifra_artikla` VARCHAR(50)). Razmaci na kraju se ne
     * broje, kao na serveru (VARCHAR ih odseca bez greške).
     */
    public const MAX_SIFRA_DUZINA = 50;

    /**
     * @param ?string $sifra   Šifra artikla: šalje se kao `sifra_artikla` (od 1.2.0;
     *                         do 1.1.1 kao `sifra`, koju server nije čitao) i server
     *                         je čuva uz stavku. Šifra duža od MAX_SIFRA_DUZINA
     *                         znakova se ne šalje (račun se izdaje bez šifre, kao do
     *                         1.1.1), jer bi server inače odbio ceo račun. Isto važi
     *                         za šifru sa NUL znakom (server je odbija). Prazna
     *                         šifra (`''`, npr. proizvod bez SKU-a) se ne šalje:
     *                         stavka se čuva bez šifre, kao do 1.1.1.
     * @param ?string $barcode GTIN (EAN): šalje se kao `gtin`; server ga prosleđuje
     *                         Poreskoj upravi samo kad je broj.
     */
    public function __construct(
        public readonly string $naziv,
        public readonly float $kolicina,
        public readonly float $jedinicnaCena,
        public readonly int $pdvStopa,
        public readonly ?string $sifra = null,
        public readonly ?string $barcode = null,
        public readonly string $jedinicaMere = 'kom',
        public readonly ?float $popust = null,
        public readonly ?float $rabatProcenat = null,
        public readonly ?TaxCategory $pdvKategorija = null,
    ) {
        if ($this->popust !== null && $this->rabatProcenat !== null) {
            throw new \InvalidArgumentException(
                'Popust i rabat_procenat ne mogu se koristiti na istoj stavci'
            );
        }

        if ($this->popust !== null && $this->popust < 0) {
            throw new \InvalidArgumentException('Popust ne sme biti negativan');
        }

        if ($this->popust !== null && $this->popust > ($this->kolicina * $this->jedinicnaCena)) {
            throw new \InvalidArgumentException(
                'Popust ne sme biti veci od iznosa stavke'
            );
        }

        if ($this->rabatProcenat !== null && ($this->rabatProcenat < 0 || $this->rabatProcenat > 100)) {
            throw new \InvalidArgumentException(
                'Rabat procenat mora biti izmedju 0 i 100'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'naziv' => $this->naziv,
            'kolicina' => $this->kolicina,
            'jedinicna_cena' => $this->jedinicnaCena,
            'pdv_stopa' => $this->pdvStopa,
            'jedinica_mere' => $this->jedinicaMere,
        ];

        if ($this->sifra !== null && $this->sifra !== '' && self::sifraStaje($this->sifra)) {
            $data['sifra_artikla'] = $this->sifra;
        }

        if ($this->barcode !== null) {
            $data['gtin'] = $this->barcode;
        }

        if ($this->popust !== null) {
            $data['popust'] = $this->popust;
        }

        if ($this->rabatProcenat !== null) {
            $data['rabat_procenat'] = $this->rabatProcenat;
        }

        if ($this->pdvKategorija !== null) {
            $data['pdv_kategorija'] = $this->pdvKategorija->value;
        }

        return $data;
    }

    /**
     * Da li server prima šifru: bez NUL znaka (server takvu odbija) i u granici
     * dužine. Broje se UTF-8 znakovi (kodne tačke, kao `mb_strlen`) bez razmaka
     * na kraju (bez zavisnosti od mbstring-a). Neispravan UTF-8 se broji po
     * bajtovima, što nikad nije manje od broja znakova.
     */
    private static function sifraStaje(string $sifra): bool
    {
        if (str_contains($sifra, "\0")) {
            return false;
        }

        $bezRazmaka = rtrim($sifra, ' ');
        $znakova = preg_match_all('/./su', $bezRazmaka);

        return ($znakova === false ? strlen($bezRazmaka) : $znakova) <= self::MAX_SIFRA_DUZINA;
    }
}
