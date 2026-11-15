<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Izdanje 1.2.0: README navodi verziju 1.2.0, odeljci 1.2.0 nisu označeni kao
 * neobjavljeni, a „Promene u 1.2.0" i „Nadogradnja na 1.2.0" navode sve
 * promene ciklusa U1a (ponavljanje) i U1b (rezultat, webhook, reklamni tekst).
 *
 * @trace R7
 */
final class Verzija120IzdanjeTest extends TestCase
{
    private static function readme(): string
    {
        $putanja = dirname(__DIR__, 2) . '/README.md';
        self::assertFileExists($putanja);

        return (string) file_get_contents($putanja);
    }

    private static function odeljak(string $naslov, string $sledeci): string
    {
        $readme = self::readme();
        $pocetak = strpos($readme, $naslov);
        self::assertNotFalse($pocetak, $naslov);
        $kraj = strpos($readme, $sledeci, $pocetak + strlen($naslov));
        self::assertNotFalse($kraj, $sledeci);

        return (string) preg_replace('/\s+/u', ' ', substr($readme, $pocetak, $kraj - $pocetak));
    }

    public function testTrenutnaVerzijaJe120(): void
    {
        $readme = self::readme();

        self::assertSame(1, substr_count($readme, 'Trenutna verzija: **'), 'tačno jedna oznaka trenutne verzije');
        self::assertStringContainsString('Trenutna verzija: **1.2.0**', $readme);
        self::assertStringContainsString('composer require infogrambeg/efiskalizacija-php-sdk:^1.2', $readme);
    }

    public function testNijedanOdeljakNijeNeobjavljen(): void
    {
        self::assertStringNotContainsString('neobjavljeno', mb_strtolower(self::readme()));
        self::assertStringContainsString("\n### Promene u 1.2.0\n", self::readme());
        self::assertStringContainsString("\n### Nadogradnja na 1.2.0\n", self::readme());
    }

    public function testPromeneNavodeU1aIU1b(): void
    {
        $promene = self::odeljak("### Promene u 1.2.0\n", "\n### Promene u 1.1.1\n");

        foreach (
            [
                // U1a
                'wasRequestSent()', '`broj_racuna`', '#[\SensitiveParameter]', 'setIdempotencyKey()',
                // U1b: rezultat
                '`qr_kod`', '`message`', 'isSuccess()', '`success`', '`status`', '`brojRacuna`', '`klijentskiBroj`',
                '`duplikat`', '`ukupanIznos`', '`pfrDatumVreme`', '`qrUrl`', '`esirOznaka`', '`errorCode`',
                '`existingCopyInvoiceId`', 'ne izuzetak',
                // U1b: webhook
                'WebhookPayload::fromArray()', '`data`', '`invoice_id` → `racunId`', '`ukupan_iznos` → `iznos`',
                '`datumIzdavanja`', '`errorMessage`', '`timestamp`',
                // U1b: reklamni tekst
                'setAdvertisementText()', '`reklamni_tekst`', 'setNote()', '`@deprecated`',
            ] as $deo
        ) {
            self::assertStringContainsString($deo, $promene, $deo);
        }
    }

    public function testNadogradnjaNavodiU1aIU1b(): void
    {
        $nadogradnja = self::odeljak("### Nadogradnja na 1.2.0\n", "\n### Nadogradnja sa 1.0.x\n");

        foreach (
            [
                // U1a
                'setInvoiceNumber()', 'requestSent', 'setIdempotencyKey()',
                // U1b
                '$result->qrCode', '$result->poruka', 'qrUrl', 'isSuccess()', 'klijentskiBroj',
                'setNote()', 'setAdvertisementText()',
            ] as $deo
        ) {
            self::assertStringContainsString($deo, $nadogradnja, $deo);
        }
    }

    /**
     * Svako novo javno svojstvo rezultata i webhook-a pomenuto je u
     * „Promene u 1.2.0" (svojstvo dodato bez dokumentacije obara test).
     */
    public function testSvakoNovoSvojstvoJeUPromenama(): void
    {
        $promene = self::odeljak("### Promene u 1.2.0\n", "\n### Promene u 1.1.1\n");
        $staro = [
            \Efiskalizacija\DTO\FiskalizacijaResult::class => ['pfrBroj', 'qrCode', 'racunId', 'requestId', 'poruka'],
            \Efiskalizacija\Webhook\WebhookPayload::class => ['event', 'pfrBroj', 'racunId', 'iznos', 'status', 'rawData'],
        ];
        foreach ($staro as $klasa => $postojeca) {
            $nova = array_diff(
                array_map(
                    static fn (\ReflectionProperty $p): string => $p->getName(),
                    (new \ReflectionClass($klasa))->getProperties(\ReflectionProperty::IS_PUBLIC),
                ),
                $postojeca,
            );
            self::assertNotEmpty($nova, $klasa);
            foreach ($nova as $svojstvo) {
                self::assertStringContainsString("`{$svojstvo}`", $promene, "{$klasa}::\${$svojstvo}");
            }
        }
    }
}
