<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

/**
 * Rezultat `POST /fiskalizacija` (`data.rezultat` iz odgovora servera).
 *
 * Neuspešna fiskalizacija koju server vrati kao rezultat (`success: false`,
 * npr. greška Poreske uprave ili odbijena kopija) ne baca izuzetak: proverite
 * `isSuccess()`, a razlog je u `poruka` (i `errorCode` za odbijenu kopiju).
 * Izuzetak se baca samo za HTTP grešku (validacija, autentifikacija,
 * nedostupan server) — vidi `EfiskalizacijaException`.
 *
 * `duplikat` je `true` kad je server vratio već izdat račun sa istim brojem
 * (`setInvoiceNumber()`), `false` kad je račun nov, a `null` kad zahtev nije
 * nosio broj računa. `qrCode` je QR kod kao base64 PNG, a `qrUrl` adresa za
 * proveru računa na portalu Poreske uprave.
 *
 * Svaka vrednost koju server pošalje u neočekivanom tipu (npr. broj kao tekst)
 * prevodi se kad je to jednoznačno, a inače postaje `null`.
 */
final class FiskalizacijaResult
{
    public function __construct(
        public readonly string $pfrBroj,
        public readonly ?string $qrCode,
        public readonly ?int $racunId,
        public readonly ?string $requestId,
        public readonly ?string $poruka = null,
        public readonly ?bool $success = null,
        public readonly ?string $status = null,
        public readonly ?string $brojRacuna = null,
        public readonly ?string $klijentskiBroj = null,
        public readonly ?bool $duplikat = null,
        public readonly ?float $ukupanIznos = null,
        public readonly ?string $pfrDatumVreme = null,
        public readonly ?string $qrUrl = null,
        public readonly ?string $esirOznaka = null,
        public readonly ?string $errorCode = null,
        public readonly ?int $existingCopyInvoiceId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data API response "rezultat" objekat
     */
    public static function fromArray(array $data): self
    {
        return new self(
            pfrBroj: Vrednost::tekst($data['pfr_broj'] ?? null) ?? '',
            qrCode: Vrednost::tekst($data['qr_kod'] ?? null) ?? Vrednost::tekst($data['qr_code'] ?? null),
            racunId: Vrednost::ceoBroj($data['racun_id'] ?? null),
            requestId: Vrednost::tekst($data['request_id'] ?? null),
            poruka: Vrednost::tekst($data['message'] ?? null) ?? Vrednost::tekst($data['poruka'] ?? null),
            success: Vrednost::logicka($data['success'] ?? null),
            status: Vrednost::tekst($data['status'] ?? null),
            brojRacuna: Vrednost::tekst($data['broj_racuna'] ?? null),
            klijentskiBroj: Vrednost::tekst($data['klijentski_broj'] ?? null),
            duplikat: Vrednost::logicka($data['duplikat'] ?? null),
            ukupanIznos: Vrednost::iznos($data['ukupan_iznos'] ?? null),
            pfrDatumVreme: Vrednost::tekst($data['pfr_datum_vreme'] ?? null),
            qrUrl: Vrednost::tekst($data['qr_url'] ?? null),
            esirOznaka: Vrednost::tekst($data['esir_oznaka'] ?? null),
            errorCode: Vrednost::tekst($data['error_code'] ?? null),
            existingCopyInvoiceId: Vrednost::ceoBroj($data['existing_copy_invoice_id'] ?? null),
        );
    }

    /**
     * Objekat sačuvan kroz `serialize()` sa 1.1.1 ili starijom verzijom nema
     * svojstva dodata u 1.2.0; ona postaju `null` (bez ovoga ostaju
     * neinicijalizovana i svaki pristup baca `Error`). Ključevi su imena
     * javnih svojstava; nepoznat ključ se zanemaruje.
     *
     * @param array<string, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->pfrBroj = $data['pfrBroj'] ?? '';
        $this->qrCode = $data['qrCode'] ?? null;
        $this->racunId = $data['racunId'] ?? null;
        $this->requestId = $data['requestId'] ?? null;
        $this->poruka = $data['poruka'] ?? null;
        $this->success = $data['success'] ?? null;
        $this->status = $data['status'] ?? null;
        $this->brojRacuna = $data['brojRacuna'] ?? null;
        $this->klijentskiBroj = $data['klijentskiBroj'] ?? null;
        $this->duplikat = $data['duplikat'] ?? null;
        $this->ukupanIznos = $data['ukupanIznos'] ?? null;
        $this->pfrDatumVreme = $data['pfrDatumVreme'] ?? null;
        $this->qrUrl = $data['qrUrl'] ?? null;
        $this->esirOznaka = $data['esirOznaka'] ?? null;
        $this->errorCode = $data['errorCode'] ?? null;
        $this->existingCopyInvoiceId = $data['existingCopyInvoiceId'] ?? null;
    }

    /**
     * Da li je račun fiskalizovan: `success` iz odgovora servera, a kad ga
     * odgovor ne nosi (stariji server, sopstveni dvojnik), neprazan `pfrBroj`.
     */
    public function isSuccess(): bool
    {
        if ($this->success !== null) {
            return $this->success;
        }

        return trim($this->pfrBroj) !== '';
    }
}
