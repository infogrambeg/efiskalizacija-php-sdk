<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Webhook\WebhookPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Server šalje polja računa u `data` (`WebhookDispatcher::buildPayload`):
 * `invoice_id`, `invoice_number`, `klijentski_broj`, `success`, `pfr_broj`,
 * `ukupan_iznos`, `datum_izdavanja` (uspeh) i `error_message` (neuspeh), a
 * `event` i `timestamp` na vrhu. Do 1.2.0 SDK je čitao `pfr_broj`,
 * `racun_id`, `iznos` i `status` sa vrha, pa su za stvarno telo bili null.
 * Stari oblik (polja na vrhu) i dalje radi.
 *
 * Unakrsni dokaz nad pravim `buildPayload` je u glavnom repou
 * (`tests/Unit/SdkOdgovorMapiranjeTest.php`).
 *
 * @trace R2
 */
final class WebhookPayloadPodaciTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function uspeh(): array
    {
        return [
            'event' => 'invoice.fiscalized',
            'timestamp' => '2026-01-05T12:00:01+01:00',
            'data' => [
                'invoice_id' => 123,
                'invoice_number' => '2026-000077',
                'klijentski_broj' => 'SHOP-2026-001',
                'success' => true,
                'pfr_broj' => 'AB12CD34-Ef5Gh6i7-101',
                'ukupan_iznos' => 2400.5,
                'datum_izdavanja' => '2026-01-05 12:00:00',
                'tip_racuna' => 'P',
                'stavke' => [['naziv' => 'Primer']],
            ],
        ];
    }

    public function testUspehCitaPoljaIzData(): void
    {
        $p = WebhookPayload::fromArray(self::uspeh());

        self::assertTrue($p->isFiscalized());
        self::assertFalse($p->isFailed());
        self::assertSame('invoice.fiscalized', $p->event);
        self::assertSame(123, $p->racunId);
        self::assertSame('AB12CD34-Ef5Gh6i7-101', $p->pfrBroj);
        self::assertSame(2400.5, $p->iznos);
        self::assertSame('SHOP-2026-001', $p->klijentskiBroj);
        self::assertSame('2026-000077', $p->brojRacuna);
        self::assertTrue($p->success);
        self::assertSame('2026-01-05 12:00:00', $p->datumIzdavanja);
        self::assertNull($p->errorMessage);
        self::assertSame('2026-01-05T12:00:01+01:00', $p->timestamp);
        self::assertSame('success', $p->status);
        self::assertSame([['naziv' => 'Primer']], $p->rawData['data']['stavke']);
    }

    public function testNeuspehNosiPorukuGreskeIStatusError(): void
    {
        $p = WebhookPayload::fromJson((string) json_encode([
            'event' => 'invoice.failed',
            'timestamp' => '2026-01-05T12:00:01+01:00',
            'data' => [
                'invoice_id' => null,
                'invoice_number' => '2026-000078',
                'klijentski_broj' => 'SHOP-2026-002',
                'success' => false,
                'error_message' => 'VSDC timeout',
            ],
        ]));

        self::assertTrue($p->isFailed());
        self::assertFalse($p->success);
        self::assertSame('error', $p->status);
        self::assertSame('VSDC timeout', $p->errorMessage);
        self::assertSame('SHOP-2026-002', $p->klijentskiBroj);
        self::assertSame('2026-000078', $p->brojRacuna);
        self::assertNull($p->racunId);
        self::assertNull($p->pfrBroj);
        self::assertNull($p->iznos);
    }

    /** `status` iz tela ima prednost nad izvedenim iz `success`. */
    #[DataProvider('statusi')]
    public function testStatus(array $telo, ?string $ocekivano): void
    {
        self::assertSame($ocekivano, WebhookPayload::fromArray($telo)->status);
    }

    /** @return array<string, array{array<string, mixed>, ?string}> */
    public static function statusi(): array
    {
        return [
            'data.status' => [['event' => 'x', 'data' => ['status' => 'naknadno', 'success' => true]], 'naknadno'],
            'status na vrhu' => [['event' => 'x', 'status' => 'failed', 'data' => ['success' => true]], 'failed'],
            // Oba postoje: data.status ima prednost nad statusom sa vrha.
            'data.status i status na vrhu' => [
                ['event' => 'x', 'status' => 'failed', 'data' => ['status' => 'naknadno', 'success' => false]],
                'naknadno',
            ],
            'iz success true' => [['event' => 'x', 'data' => ['success' => true]], 'success'],
            'iz success false' => [['event' => 'x', 'data' => ['success' => false]], 'error'],
            'bez success i statusa' => [['event' => 'x', 'data' => []], null],
            'success nije bool' => [['event' => 'x', 'data' => ['success' => 'mozda']], null],
        ];
    }

    /** Stari oblik (polja na vrhu, bez `data`) radi kao do sada. */
    public function testStariOblikSaVrha(): void
    {
        $p = WebhookPayload::fromArray([
            'event' => 'invoice.fiscalized',
            'pfr_broj' => 'ABC-123',
            'racun_id' => 42,
            'iznos' => 15000.50,
            'status' => 'success',
        ]);

        self::assertSame('ABC-123', $p->pfrBroj);
        self::assertSame(42, $p->racunId);
        self::assertSame(15000.50, $p->iznos);
        self::assertSame('success', $p->status);
        self::assertNull($p->klijentskiBroj);
        self::assertNull($p->success);
        self::assertNull($p->timestamp);
    }

    /** Polje u `data` ima prednost; stari ključ sa vrha je rezerva kad ga u `data` nema ili je null. */
    public function testDataImaPrednostNadVrhom(): void
    {
        $p = WebhookPayload::fromArray([
            'event' => 'invoice.fiscalized',
            'pfr_broj' => 'STARI',
            'racun_id' => 1,
            'iznos' => 1.0,
            'data' => ['pfr_broj' => 'NOVI', 'invoice_id' => 2, 'ukupan_iznos' => null],
        ]);

        self::assertSame('NOVI', $p->pfrBroj);
        self::assertSame(2, $p->racunId);
        self::assertSame(1.0, $p->iznos);
    }

    /** Oba iznosa postoje: `data.ukupan_iznos` ima prednost nad `iznos` sa vrha. */
    public function testUkupanIznosIzDataImaPrednostNadIznosomSaVrha(): void
    {
        $p = WebhookPayload::fromArray([
            'event' => 'invoice.fiscalized',
            'iznos' => 1.0,
            'data' => ['ukupan_iznos' => '2400.50'],
        ]);

        self::assertSame(2400.5, $p->iznos);
    }

    /**
     * `data` koji nije niz (ni objekat sa pristupom kao niz) se ne čita:
     * polja dolaze samo sa vrha.
     */
    public function testDataKojiJeObjekatSeNeCita(): void
    {
        $p = WebhookPayload::fromArray([
            'event' => 'invoice.fiscalized',
            'pfr_broj' => 'X-1',
            'data' => new \ArrayObject(['pfr_broj' => 'IZ-OBJEKTA', 'success' => true, 'klijentski_broj' => 'K-1']),
        ]);

        self::assertSame('X-1', $p->pfrBroj);
        self::assertNull($p->success);
        self::assertNull($p->klijentskiBroj);
        self::assertNull($p->status);
    }

    /** `data` koji nije niz se ignoriše (polja se čitaju sa vrha), bez TypeError-a. */
    public function testDataKojiNijeNiz(): void
    {
        $p = WebhookPayload::fromArray(['event' => 'invoice.fiscalized', 'data' => 'tekst', 'pfr_broj' => 'X-1']);

        self::assertSame('X-1', $p->pfrBroj);
        self::assertNull($p->klijentskiBroj);
    }

    public function testTolerantniTipovi(): void
    {
        $p = WebhookPayload::fromArray([
            'event' => 'invoice.fiscalized',
            'timestamp' => 12,
            'data' => [
                'invoice_id' => '123',
                'ukupan_iznos' => '2400.50',
                'klijentski_broj' => 77,
                'invoice_number' => ['x'],
                'success' => 1,
                'pfr_broj' => 101,
            ],
        ]);

        self::assertSame(123, $p->racunId);
        self::assertSame(2400.5, $p->iznos);
        self::assertSame('77', $p->klijentskiBroj);
        self::assertNull($p->brojRacuna);
        self::assertTrue($p->success);
        self::assertSame('101', $p->pfrBroj);
        self::assertSame('12', $p->timestamp);
    }

    /** `event` koji nije tekst daje prazan string, kao kad ga nema. */
    public function testEventKojiNijeTekst(): void
    {
        self::assertSame('', WebhookPayload::fromArray(['event' => ['x']])->event);
        self::assertSame('', WebhookPayload::fromArray([])->event);
    }

    /** Postojeći poziv konstruktora sa šest argumenata radi; nova svojstva su na kraju (BC). */
    public function testKonstruktorSaSestArgumenataRadi(): void
    {
        $p = new WebhookPayload('invoice.failed', null, 1, null, 'failed', ['a' => 1]);

        self::assertTrue($p->isFailed());
        self::assertSame(['a' => 1], $p->rawData);
        self::assertNull($p->klijentskiBroj);

        $parametri = (new \ReflectionMethod(WebhookPayload::class, '__construct'))->getParameters();
        self::assertSame(
            ['event', 'pfrBroj', 'racunId', 'iznos', 'status', 'rawData'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), array_slice($parametri, 0, 6)),
        );
        foreach (array_slice($parametri, 6) as $p) {
            self::assertTrue($p->isDefaultValueAvailable(), $p->getName() . ' ima podrazumevanu vrednost');
        }
    }
}
