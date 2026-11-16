<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * README, „Avansni računi": neuspeh fiskalizacije je rezultat, ne izuzetak,
 * pa primer posle svakog `fiskalizuj()` proverava `isSuccess()` i staje pre
 * nego što `pfrBroj` tog rezultata ode u `setReferentDocument()`.
 *
 * README, „Brzi početak": `errorCode` i `existingCopyInvoiceId` server
 * popunjava samo za `tip_racuna: "copy"` uz `original_broj_racuna`; SDK ga
 * šalje samo uz `setOriginalInvoiceNumber()` (od 1.2.0), inače `InvoiceType::Kopija`
 * šalje `kopija` kao do 1.1.1.
 *
 * @trace R1
 */
final class ReadmeAvansniLanacTest extends TestCase
{
    public function testSvakaReferencaJeIzaProvereUspeha(): void
    {
        self::assertSame([], self::odstupanja(self::avansniPrimer()));
    }

    public function testProveraVidiPrimerBezProvere(): void
    {
        $primer = self::avansniPrimer();
        foreach (['$avansResult', '$refundResult'] as $rezultat) {
            $provera = "if (!{$rezultat}->isSuccess()) {";
            self::assertSame(1, substr_count($primer, $provera), $rezultat);
            $pokvaren = str_replace($provera, 'if (false) {', $primer);
            self::assertNotSame([], self::odstupanja($pokvaren), "{$rezultat}: provera mora pasti");
        }
    }

    public function testKodGreskeKopijeSamoUzBrojOriginala(): void
    {
        $readme = (string) preg_replace('/\s+/u', ' ', self::readme());

        self::assertStringContainsString('`tip_racuna: "copy"`', $readme);
        self::assertStringContainsString('`original_broj_racuna`', $readme);
        self::assertStringContainsString('SDK takav zahtev šalje kad uz `InvoiceType::Kopija` postavite `setOriginalInvoiceNumber()`', $readme);
        self::assertStringContainsString('bez broja originala su `null`', $readme);
        self::assertStringNotContainsString('trenutno ne šalje', $readme); // @trace R4
        self::assertStringNotContainsString('"copy_already_issued" / "copy_in_progress" ili null', $readme);
        self::assertSame('kopija', \Efiskalizacija\Enum\InvoiceType::Kopija->value);
    }

    /**
     * Za svaki `setReferentDocument($x->pfrBroj)`: `$x` je rezultat
     * `fiskalizuj()`, a između dodele i reference stoji
     * `if (!$x->isSuccess()) { throw ...` (prazno = ispravno).
     *
     * @return list<string>
     */
    private static function odstupanja(string $primer): array
    {
        $odstupanja = [];
        preg_match_all('/->setReferentDocument\((\$\w+)->pfrBroj\)/', $primer, $m, PREG_OFFSET_CAPTURE);
        if (count($m[0]) < 2) {
            return ['primer nema bar dve reference na prethodni rezultat'];
        }
        foreach ($m[1] as [$promenljiva, $pozicija]) {
            $dodela = strrpos(substr($primer, 0, $pozicija), $promenljiva . ' = $client->fiskalizacija()->fiskalizuj(');
            if ($dodela === false) {
                $odstupanja[] = "{$promenljiva}: nije rezultat fiskalizuj() pre reference";
                continue;
            }
            $izmedju = substr($primer, $dodela, $pozicija - $dodela);
            if (preg_match('/if \(!' . preg_quote($promenljiva, '/') . '->isSuccess\(\)\) \{\s*(?:\/\/[^\n]*\n\s*)*throw /', $izmedju) !== 1) {
                $odstupanja[] = "{$promenljiva}: referenca bez provere isSuccess() i prekida";
            }
        }

        return $odstupanja;
    }

    private static function avansniPrimer(): string
    {
        $readme = self::readme();
        $pocetak = strpos($readme, "\n## Avansni računi\n");
        self::assertNotFalse($pocetak);
        $kraj = strpos($readme, "\n## ", $pocetak + 1);
        self::assertNotFalse($kraj);
        $odeljak = substr($readme, $pocetak, $kraj - $pocetak);
        self::assertSame(1, preg_match('/```php\n(.*?)```/s', $odeljak, $m), 'PHP primer u odeljku');

        return $m[1];
    }

    private static function readme(): string
    {
        $putanja = dirname(__DIR__, 2) . '/README.md';
        self::assertFileExists($putanja);

        return (string) file_get_contents($putanja);
    }
}
