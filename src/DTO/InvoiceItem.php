<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

use Efiskalizacija\Enum\TaxCategory;

final class InvoiceItem
{
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

        if ($this->sifra !== null) {
            $data['sifra'] = $this->sifra;
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
}
