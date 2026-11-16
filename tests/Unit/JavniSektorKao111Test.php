<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Customer;
use Efiskalizacija\DTO\Invoice;
use PHPUnit\Framework\TestCase;

/**
 * `Customer::javniSektor()` bez `$pib` šalje kupca bajt-isto kao 1.1.1
 * (`tip: "javni_sektor"` + `identifikator`, vrednost kakva je prosleđena), pa
 * server takav račun izdaje ili odbija kao do sada. Dokaz je između verzija:
 * `toArray()` klasa iz `git archive v1.1.1` (pod-proces) naspram radne kopije,
 * i serijal `Customer`/`Invoice` napravljen u 1.1.1 pa pročitan radnom kopijom.
 *
 * @trace R2
 */
final class JavniSektorKao111Test extends TestCase
{
    /** Vrednosti koje je 1.1.1 primao bez provere (server odlučuje po broju cifara). */
    private const VREDNOSTI = ['12345', '123456789', '1234567890123', '123-456-789', "123456789\n", '', '0'];

    /** @var array<string, mixed>|null */
    private static ?array $v111 = null;

    /**
     * Pozicioni argumenti `javniSektor()` bez `$pib`, za svaku vrednost u tri
     * oblika (samo JBKJS, uz ime, uz ime i email).
     *
     * @return array<string, list<?string>>
     */
    private static function pozivi(): array
    {
        $pozivi = [];
        foreach (self::VREDNOSTI as $i => $v) {
            $pozivi["v{$i}"] = [$v];
            $pozivi["v{$i}_ime"] = [$v, 'Opstina'];
            $pozivi["v{$i}_ime_email"] = [$v, 'Opstina', 'opstina@test.rs'];
            $pozivi["v{$i}_email"] = [$v, null, 'opstina@test.rs'];
        }

        return $pozivi;
    }

    /** @trace R2 */
    public function testBezPibaToArrayJeKaoU111(): void
    {
        $v111 = self::v111();

        foreach (self::pozivi() as $ime => $argumenti) {
            self::assertSame($v111[$ime]['kupac'], Customer::javniSektor(...$argumenti)->toArray(), $ime);
        }

        // Kontrola: 1.1.1 je zaista slao stari oblik, sa vrednošću bez izmene
        self::assertSame(['tip' => 'javni_sektor', 'identifikator' => "123456789\n"], $v111['v4']['kupac']);
        self::assertSame(['ime' => 'Opstina', 'tip' => 'javni_sektor', 'identifikator' => '1234567890123', 'email' => 'opstina@test.rs'], $v111['v2_ime_email']['kupac']);
    }

    /** Serijal iz 1.1.1 pročitan radnom kopijom šalje isto telo kao 1.1.1. @trace R2 */
    public function testSerijalIz111SaljeIstoTelo(): void
    {
        $v111 = self::v111();

        foreach (self::pozivi() as $ime => $_) {
            $kupac = unserialize(base64_decode($v111[$ime]['customer'], true), ['allowed_classes' => [Customer::class]]);
            self::assertInstanceOf(Customer::class, $kupac);
            self::assertSame($v111[$ime]['kupac'], $kupac->toArray(), $ime . ' (Customer)');

            $racun = unserialize(base64_decode($v111[$ime]['invoice'], true));
            self::assertInstanceOf(Invoice::class, $racun);
            self::assertSame($v111[$ime]['racun_kupac'], $racun->toArray()['kupac'], $ime . ' (Invoice)');
            self::assertSame($v111[$ime]['kupac'], $v111[$ime]['racun_kupac'], 'kontrola: Invoice nosi kupca iz Customer::toArray()');
        }
    }

    /**
     * Klase iz taga v1.1.1 (pod-proces): za svaki poziv `toArray()` kupca i
     * serijal `Customer`/`Invoice` (base64).
     *
     * @return array<string, mixed>
     */
    private static function v111(): array
    {
        if (self::$v111 !== null) {
            return self::$v111;
        }

        $koren = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/efisk-sdk-v111-js-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700), 'privremeni direktorijum');

        try {
            $arhiva = PodProces::izvrsi(['git', '-C', $koren, 'archive', '--output=' . $dir . '/src.tar', 'v1.1.1', 'src']);
            self::assertSame(0, $arhiva['kod'], 'git archive v1.1.1 (tag mora postojati): ' . $arhiva['greske']);
            $raspakivanje = PodProces::izvrsi(['tar', '-x', '-f', $dir . '/src.tar', '-C', $dir]);
            self::assertSame(0, $raspakivanje['kod'], $raspakivanje['greske']);

            $skript = $dir . '/kupci.php';
            file_put_contents($skript, <<<'PHP'
                <?php
                declare(strict_types=1);
                $src = $argv[1];
                spl_autoload_register(static function (string $c) use ($src): void {
                    $p = 'Efiskalizacija\\';
                    if (strncmp($c, $p, strlen($p)) === 0) {
                        $f = $src . '/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php';
                        if (is_file($f)) {
                            require $f;
                        }
                    }
                });
                $izlaz = ['fajl' => null, 'pozivi' => []];
                foreach (json_decode(base64_decode($argv[2], true), true, 8, JSON_THROW_ON_ERROR) as $ime => $argumenti) {
                    $kupac = \Efiskalizacija\DTO\Customer::javniSektor(...$argumenti);
                    $racun = \Efiskalizacija\DTO\Invoice::create()
                        ->setPaymentType(\Efiskalizacija\Enum\PaymentType::Virman)
                        ->setCustomer($kupac)
                        ->addItem(new \Efiskalizacija\DTO\InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 100.0, pdvStopa: 20));
                    $izlaz['pozivi'][$ime] = [
                        'kupac' => $kupac->toArray(),
                        'racun_kupac' => $racun->toArray()['kupac'],
                        'customer' => base64_encode(serialize($kupac)),
                        'invoice' => base64_encode(serialize($racun)),
                    ];
                }
                $izlaz['fajl'] = (new \ReflectionClass(\Efiskalizacija\DTO\Customer::class))->getFileName();
                echo json_encode($izlaz, JSON_THROW_ON_ERROR);
                PHP);

            $ulaz = base64_encode(json_encode(self::pozivi(), JSON_THROW_ON_ERROR));
            $izlaz = PodProces::izvrsi([PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=stderr', $skript, $dir . '/src', $ulaz]);
            self::assertSame(0, $izlaz['kod'], $izlaz['greske'] . $izlaz['izlaz']);
            self::assertSame('', $izlaz['greske']);
            $r = json_decode($izlaz['izlaz'], true, 16, JSON_THROW_ON_ERROR);

            // Kontrola: pod-proces je učitao klasu iz taga, ne radnu kopiju
            self::assertSame(realpath($dir . '/src/DTO/Customer.php'), realpath((string) $r['fajl']));
            self::assertSame(array_keys(self::pozivi()), array_keys($r['pozivi']));

            return self::$v111 = $r['pozivi'];
        } finally {
            foreach (['src.tar', 'kupci.php'] as $f) {
                @unlink($dir . '/' . $f);
            }
            if (is_dir($dir . '/src')) {
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir . '/src', \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($it as $f) {
                    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
                }
                rmdir($dir . '/src');
            }
            rmdir($dir);
        }
    }
}
