<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * README i docblock-ovi prate 1.1.1: trenutna verzija, odeljak promena i
 * granica `maxRetries` (više nije „neograničen").
 *
 * @trace R5
 */
final class Verzija111DokumentacijaTest extends TestCase
{
    private static function fajl(string $relativno): string
    {
        $putanja = dirname(__DIR__, 2) . '/' . $relativno;
        self::assertFileExists($putanja);

        return (string) file_get_contents($putanja);
    }

    public function testReadmeNavodiVerziju111IZadrzavaNapomenuOV2(): void
    {
        $readme = self::fajl('README.md');

        // Trenutnu verziju (posle 1.1.1) čuva Verzija120IzdanjeTest
        self::assertStringNotContainsString('Trenutna verzija: **1.1.0**', $readme);
        self::assertStringContainsString('zahtevaju server sa podrškom za potpis v2', $readme);
    }

    public function testOdeljakPromenaU111JeIznad110(): void
    {
        $readme = self::fajl('README.md');

        $novi = strpos($readme, "### Promene u 1.1.1\n");
        $stari = strpos($readme, "### Promene u 1.1.0\n");
        self::assertNotFalse($novi, 'odeljak „Promene u 1.1.1"');
        self::assertNotFalse($stari, 'odeljak „Promene u 1.1.0" ostaje');
        self::assertLessThan($stari, $novi);

        $odeljak = substr($readme, $novi, $stari - $novi);
        self::assertStringContainsString('curl_close', $odeljak);
        self::assertStringContainsString('E_DEPRECATED', $odeljak);
        self::assertStringContainsString('0–10', $odeljak);
        self::assertStringContainsString('InvalidArgumentException', $odeljak);
        self::assertStringContainsString('60 s', $odeljak);
    }

    private static function odeljak111(): string
    {
        $readme = self::fajl('README.md');
        $novi = strpos($readme, "### Promene u 1.1.1\n");
        $stari = strpos($readme, "### Promene u 1.1.0\n");
        self::assertNotFalse($novi);
        self::assertNotFalse($stari);

        return substr($readme, $novi, $stari - $novi);
    }

    public function testOdeljak111NavodiSensitiveParameterIGraniceNaPhp81(): void
    {
        $odeljak = self::odeljak111();

        self::assertStringContainsString('#[\SensitiveParameter]', $odeljak);
        foreach (
            [
                'Config::__construct',
                'Config::sandbox',
                'Config::production',
                'EfiskalizacijaClient::create',
                'EfiskalizacijaClient::sandbox',
                'HmacSigner::__construct',
            ] as $mesto
        ) {
            self::assertStringContainsString($mesto, $odeljak, $mesto);
        }
        self::assertStringContainsString('PHP ≥ 8.2', $odeljak);
        self::assertStringContainsString('PHP 8.1', $odeljak);
        self::assertStringContainsString('zend.exception_ignore_args=On', $odeljak);
        self::assertStringContainsString("Serialization of 'SensitiveParameterValue' is not allowed", $odeljak);
    }

    public function testOdeljak111NavodiPonasanjeDeserijalizovanogConfiga(): void
    {
        $odeljak = self::odeljak111();

        self::assertStringContainsString('unserialize()', $odeljak);
        self::assertStringContainsString('`maxRetries` > 10', $odeljak);
        self::assertStringContainsString('najviše 10 ponovljenih pokušaja', $odeljak);
        self::assertStringContainsString('negativnim `maxRetries`', $odeljak);
        self::assertStringContainsString('pre slanja', $odeljak);
    }

    public function testOdeljak111NeTvrdiDaJeOstaloPonasanjeBezPromene(): void
    {
        $odeljak = mb_strtolower(self::odeljak111());

        foreach (
            [
                'ostalo ponašanje su bez promene',
                'javne metode i ostalo',
                'samo one navedene',
                'izmene ponašanja su samo',
                'sve drugo',
                'sve ostalo',
                'isto kao u 1.1.0',
                'isto kao 1.1.0',
                'ostalo je nepromenjeno',
                'ostalo je isto',
                'nema drugih izmena',
                'nema drugih promena',
            ] as $univerzalno
        ) {
            self::assertStringNotContainsString($univerzalno, $odeljak, $univerzalno);
        }
    }

    public function testOdeljak111NavodiPauzeDeserijalizovanogConfigaVanOpsega(): void
    {
        $odeljak = self::odeljak111();

        self::assertStringContainsString('sa `retryBaseDelayMs` < 0 pauza je 0', $odeljak);
        self::assertStringContainsString('sa `retryMultiplier` < 1 svaka pauza je jednaka bazi', $odeljak);
    }

    public function testOdeljak111SuzavaSensitiveParameterNaConfigIFabrike(): void
    {
        $odeljak = self::odeljak111();

        self::assertStringContainsString('trag izuzetka iz `Config`-a i fabrika', $odeljak);
        self::assertStringContainsString('`NetworkException::getTrace()`', $odeljak);
        self::assertStringContainsString('`X-API-Key`', $odeljak);
        self::assertStringContainsString('`X-Signature`', $odeljak);
        self::assertStringContainsString('na svakoj verziji PHP-a', $odeljak);
        self::assertMatchesRegularExpression(
            '/uz\s+`zend\.exception_ignore_args=0`[^.]*`serialize\(\)`|`serialize\(\)`[^.]*uz\s+`zend\.exception_ignore_args=0`/u',
            $odeljak,
            'posledica na serialize() vezana za zend.exception_ignore_args=0'
        );
    }

    private static function odeljakIzmedju(string $naslov, string $sledeci): string
    {
        $readme = self::fajl('README.md');
        $pocetak = strpos($readme, $naslov);
        self::assertNotFalse($pocetak, $naslov);
        $kraj = strpos($readme, $sledeci, $pocetak + strlen($naslov));
        self::assertNotFalse($kraj, $sledeci);

        return substr($readme, $pocetak, $kraj - $pocetak);
    }

    public function testNadogradnjaNavodiIzuzetakZaConfigVanOpsega(): void
    {
        $odeljak = self::odeljakIzmedju("### Nadogradnja sa 1.0.x\n", "\n## Retry logika\n");

        self::assertStringContainsString('`maxRetries` van 0–10', $odeljak);
        self::assertStringContainsString('`retryBaseDelayMs` < 0', $odeljak);
        self::assertStringContainsString('`retryMultiplier` < 1', $odeljak);
        self::assertStringContainsString('baca `InvalidArgumentException` pri konstrukciji od 1.1.1', $odeljak);
        self::assertStringContainsString('najviše 60 s', $odeljak);
    }

    public function testRetryGranicaNeTvrdiSvodjenjeNaOpsegZaUnserialize(): void
    {
        $odeljak = self::odeljakIzmedju("## Retry logika\n", "\n## Webhook\n");

        self::assertStringNotContainsString('svodi na opseg', $odeljak);
        self::assertStringContainsString('`maxRetries` > 10 se ograničava na 10', $odeljak);
        self::assertStringContainsString('negativan baca `InvalidArgumentException` pre slanja', $odeljak);
    }

    public function testNigdeSeNeTvrdiDaMaxRetriesNijeOgranicen(): void
    {
        foreach (['README.md', 'src/Api/AbstractApi.php', 'src/Config.php'] as $fajl) {
            self::assertStringNotContainsString('nije ograničen', self::fajl($fajl), $fajl);
        }
    }

    public function testDocblockNextTimestampNavodiGranicu(): void
    {
        $izvor = self::fajl('src/Api/AbstractApi.php');
        $kraj = strpos($izvor, 'private function nextTimestamp(');
        self::assertNotFalse($kraj);
        $pocetak = strrpos(substr($izvor, 0, $kraj), '/**');
        self::assertNotFalse($pocetak);
        $docblock = substr($izvor, $pocetak, $kraj - $pocetak);

        self::assertStringContainsString('Config::MAX_RETRIES', $docblock);
        self::assertStringNotContainsString('nije ograničen', $docblock);
    }
}
