<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\TenantStatus;
use PHPUnit\Framework\TestCase;

/**
 * `GET /status`: server šalje `tenant.max_invoices_per_month: null` za nalog
 * bez mesečnog limita, a `maxInvoicesPerMonth` je `int` (null postaje 0, isto
 * kao „limit 0"). Od 1.2.0 `unlimitedInvoices` to razlikuje; `maxInvoicesPerMonth`
 * je i dalje 0 za takav nalog (BC).
 *
 * Telo sa pravog servera kroz SDK proverava unakrsni test glavnog repoa
 * (`tests/Unit/SdkStatusIListaOdgovorTest.php`).
 *
 * @trace R3
 */
final class TenantStatusTest extends TestCase
{
    /** `TenantStatus` serijalizovan klasom iz `ec2b1d7` (pre ovog ciklusa). */
    private const SERIJAL_PRE = 'O:31:"Efiskalizacija\DTO\TenantStatus":9:{s:5:"naziv";s:9:"Firma DOO";s:3:"pib";s:9:"123456789";'
        . 's:11:"environment";s:4:"test";s:20:"currentMonthInvoices";i:12;s:19:"maxInvoicesPerMonth";i:500;'
        . 's:13:"totalInvoices";i:340;s:9:"thisMonth";i:12;s:11:"successRate";d:99.5;s:7:"version";s:5:"1.2.0";}';

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private static function telo(array $tenant): array
    {
        return [
            'tenant' => array_merge(
                ['naziv' => 'Firma DOO', 'pib' => '123456789', 'environment' => 'production', 'current_month_invoices' => 7],
                $tenant
            ),
            'statistics' => ['total_invoices' => 40, 'this_month' => 7, 'success_rate' => 97.5],
            'system' => ['version' => '1.2.0', 'api_version' => 'v1', 'timestamp' => '2026-11-15T10:00:00+01:00'],
        ];
    }

    public function testNalogBezLimitaJeNeogranicen(): void
    {
        $status = TenantStatus::fromArray(self::telo(['max_invoices_per_month' => null]));

        self::assertTrue($status->unlimitedInvoices);
        self::assertSame(0, $status->maxInvoicesPerMonth, 'BC: kao do 1.1.1');
    }

    public function testNalogSaLimitomNijeNeogranicen(): void
    {
        foreach ([500, '500', 0] as $limit) {
            $status = TenantStatus::fromArray(self::telo(['max_invoices_per_month' => $limit]));

            self::assertFalse($status->unlimitedInvoices, var_export($limit, true));
            self::assertSame((int) $limit, $status->maxInvoicesPerMonth);
        }
    }

    /** Telo bez ključa (npr. sopstveni dvojnik) ne tvrdi da limita nema. */
    public function testBezKljucaNijeNeogranicen(): void
    {
        $telo = self::telo([]);

        self::assertFalse(TenantStatus::fromArray($telo)->unlimitedInvoices);
        self::assertFalse(TenantStatus::fromArray([])->unlimitedInvoices);
    }

    public function testOstalaPoljaSuKaoRanije(): void
    {
        $status = TenantStatus::fromArray(self::telo(['max_invoices_per_month' => 500]));

        self::assertSame('Firma DOO', $status->naziv);
        self::assertSame('123456789', $status->pib);
        self::assertSame('production', $status->environment);
        self::assertSame(7, $status->currentMonthInvoices);
        self::assertSame(40, $status->totalInvoices);
        self::assertSame(7, $status->thisMonth);
        self::assertSame(97.5, $status->successRate);
        self::assertSame('1.2.0', $status->version);
    }

    /** Konstruktor sa devet argumenata (1.1.1) radi; novo svojstvo je poslednje, podrazumevano false. */
    public function testKonstruktorIz111Radi(): void
    {
        $status = new TenantStatus('Firma', '123456789', 'test', 1, 10, 2, 1, 50.0, '1.1.1');

        self::assertFalse($status->unlimitedInvoices);
        $parametri = (new \ReflectionMethod(TenantStatus::class, '__construct'))->getParameters();
        self::assertSame('unlimitedInvoices', end($parametri)->getName());
        self::assertTrue(end($parametri)->isDefaultValueAvailable());
    }

    public function testSerijalPreOvogIzdanjaSeCitaSaSvimSvojstvima(): void
    {
        $status = unserialize(self::SERIJAL_PRE, ['allowed_classes' => [TenantStatus::class]]);
        self::assertInstanceOf(TenantStatus::class, $status);

        foreach ((new \ReflectionClass($status))->getProperties() as $p) {
            self::assertTrue($p->isInitialized($status), $p->getName());
        }
        self::assertFalse($status->unlimitedInvoices);
        self::assertSame(500, $status->maxInvoicesPerMonth);
        self::assertSame('Firma DOO', $status->naziv);
        self::assertSame(99.5, $status->successRate);
    }

    public function testSerijalizacijaUObaSmera(): void
    {
        $status = TenantStatus::fromArray(self::telo(['max_invoices_per_month' => null]));

        $nazad = unserialize(serialize($status), ['allowed_classes' => [TenantStatus::class]]);

        self::assertEquals($status, $nazad);
        self::assertTrue($nazad->unlimitedInvoices);
    }
}
