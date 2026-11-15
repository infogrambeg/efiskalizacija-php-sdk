<?php

declare(strict_types=1);

namespace Efiskalizacija\Webhook;

use Efiskalizacija\DTO\Vrednost;

/**
 * Telo webhook događaja `invoice.fiscalized` / `invoice.failed`.
 *
 * Server šalje `event` i `timestamp` na vrhu, a polja računa u `data`:
 * `invoice_id` (`racunId`), `invoice_number` (`brojRacuna`, broj koji je
 * dodelio sistem), `klijentski_broj` (`klijentskiBroj`, broj koji ste poslali
 * kroz `setInvoiceNumber()`; null kad ga niste poslali), `success`,
 * `pfr_broj`, `ukupan_iznos` (`iznos`), `datum_izdavanja` i, za neuspeh,
 * `error_message` (`errorMessage`). Ceo dekodiran JSON je u `rawData`.
 *
 * Stari oblik sa poljima na vrhu (`pfr_broj`, `racun_id`, `iznos`, `status`)
 * se i dalje čita kad polja nema u `data`. `status` je `data.status`, pa
 * `status` sa vrha, a kad ga nema — `success` ili `error` prema `success`.
 */
final class WebhookPayload
{
    public function __construct(
        public readonly string $event,
        public readonly ?string $pfrBroj,
        public readonly ?int $racunId,
        public readonly ?float $iznos,
        public readonly ?string $status,
        /** @var array<string, mixed> */
        public readonly array $rawData,
        public readonly ?string $klijentskiBroj = null,
        public readonly ?string $brojRacuna = null,
        public readonly ?bool $success = null,
        public readonly ?string $datumIzdavanja = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $timestamp = null,
    ) {
    }

    /**
     * Parsiraj webhook payload iz JSON stringa.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Neispravan webhook JSON payload');
        }

        return self::fromArray($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $polja = is_array($data['data'] ?? null) ? $data['data'] : [];
        $success = Vrednost::logicka($polja['success'] ?? null);

        $status = Vrednost::tekst($polja['status'] ?? null) ?? Vrednost::tekst($data['status'] ?? null);
        if ($status === null && $success !== null) {
            $status = $success ? 'success' : 'error';
        }

        return new self(
            event: Vrednost::tekst($data['event'] ?? null) ?? '',
            pfrBroj: Vrednost::tekst($polja['pfr_broj'] ?? null) ?? Vrednost::tekst($data['pfr_broj'] ?? null),
            racunId: Vrednost::ceoBroj($polja['invoice_id'] ?? null) ?? Vrednost::ceoBroj($data['racun_id'] ?? null),
            iznos: Vrednost::iznos($polja['ukupan_iznos'] ?? null) ?? Vrednost::iznos($data['iznos'] ?? null),
            status: $status,
            rawData: $data,
            klijentskiBroj: Vrednost::tekst($polja['klijentski_broj'] ?? null),
            brojRacuna: Vrednost::tekst($polja['invoice_number'] ?? null),
            success: $success,
            datumIzdavanja: Vrednost::tekst($polja['datum_izdavanja'] ?? null),
            errorMessage: Vrednost::tekst($polja['error_message'] ?? null),
            timestamp: Vrednost::tekst($data['timestamp'] ?? null),
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
        $this->event = $data['event'] ?? '';
        $this->pfrBroj = $data['pfrBroj'] ?? null;
        $this->racunId = $data['racunId'] ?? null;
        $this->iznos = $data['iznos'] ?? null;
        $this->status = $data['status'] ?? null;
        $this->rawData = $data['rawData'] ?? [];
        $this->klijentskiBroj = $data['klijentskiBroj'] ?? null;
        $this->brojRacuna = $data['brojRacuna'] ?? null;
        $this->success = $data['success'] ?? null;
        $this->datumIzdavanja = $data['datumIzdavanja'] ?? null;
        $this->errorMessage = $data['errorMessage'] ?? null;
        $this->timestamp = $data['timestamp'] ?? null;
    }

    public function isFiscalized(): bool
    {
        return $this->event === 'invoice.fiscalized';
    }

    public function isFailed(): bool
    {
        return $this->event === 'invoice.failed';
    }
}
