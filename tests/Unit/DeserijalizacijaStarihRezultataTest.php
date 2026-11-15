<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\FiskalizacijaResult;
use Efiskalizacija\Webhook\WebhookPayload;
use PHPUnit\Framework\TestCase;

/**
 * `FiskalizacijaResult` i `WebhookPayload` sačuvani kroz `serialize()` sa
 * 1.0.x/1.1.x (keš, red poruka, sesija) posle nadogradnje se deserijalizuju
 * sa svim svojstvima inicijalizovanim: stara nose sačuvanu vrednost, nova su
 * `null`. Bez `__unserialize()` nova readonly svojstva ostaju
 * neinicijalizovana i svaki pristup (`isSuccess()`, `$success`, `$duplikat`,
 * `$klijentskiBroj`) baca `Error`.
 *
 * Oblik serijala je isti u 1.0.2 i 1.1.1 (klase su bajt-iste); doslovne
 * vrednosti ispod napravljene su klasama iz `git archive v1.1.1`, a
 * {@see testSerijalIzV111JeIstiKaoDoslovni} to ponovo proverava nad tagom.
 */
final class DeserijalizacijaStarihRezultataTest extends TestCase
{
    private const REZULTAT_V111 = 'O:38:"Efiskalizacija\DTO\FiskalizacijaResult":5:{s:7:"pfrBroj";s:4:"AB-1";'
        . 's:6:"qrCode";s:4:"cXI=";s:7:"racunId";i:5;s:9:"requestId";s:5:"req-1";s:6:"poruka";s:7:"Uspesno";}';

    private const WEBHOOK_V111 = 'O:37:"Efiskalizacija\Webhook\WebhookPayload":6:{s:5:"event";s:18:"invoice.fiscalized";'
        . 's:7:"pfrBroj";s:4:"AB-1";s:7:"racunId";i:7;s:5:"iznos";d:1190.5;s:6:"status";s:7:"success";'
        . 's:7:"rawData";a:1:{s:5:"event";s:18:"invoice.fiscalized";}}';

    private const NOVA_SVOJSTVA_REZULTATA = [
        'success', 'status', 'brojRacuna', 'klijentskiBroj', 'duplikat', 'ukupanIznos',
        'pfrDatumVreme', 'qrUrl', 'esirOznaka', 'errorCode', 'existingCopyInvoiceId',
    ];

    private const NOVA_SVOJSTVA_WEBHOOKA = [
        'klijentskiBroj', 'brojRacuna', 'success', 'datumIzdavanja', 'errorMessage', 'timestamp',
    ];

    private static function rezultat(string $serijal): FiskalizacijaResult
    {
        $r = unserialize($serijal, ['allowed_classes' => [FiskalizacijaResult::class]]);
        self::assertInstanceOf(FiskalizacijaResult::class, $r);

        return $r;
    }

    private static function webhook(string $serijal): WebhookPayload
    {
        $w = unserialize($serijal, ['allowed_classes' => [WebhookPayload::class]]);
        self::assertInstanceOf(WebhookPayload::class, $w);

        return $w;
    }

    /**
     * Svako javno svojstvo je inicijalizovano (pristup ne baca `Error`).
     */
    private static function assertSveInicijalizovano(object $o): void
    {
        foreach ((new \ReflectionClass($o))->getProperties() as $p) {
            self::assertTrue($p->isInitialized($o), $p->getName() . ' je inicijalizovano');
        }
    }

    /** @trace R1 */
    public function testRezultatIzV111ZadrzavaStaraSvojstvaANovaSuNull(): void
    {
        $r = self::rezultat(self::REZULTAT_V111);

        self::assertSveInicijalizovano($r);
        self::assertSame('AB-1', $r->pfrBroj);
        self::assertSame('cXI=', $r->qrCode);
        self::assertSame(5, $r->racunId);
        self::assertSame('req-1', $r->requestId);
        self::assertSame('Uspesno', $r->poruka);
        foreach (self::NOVA_SVOJSTVA_REZULTATA as $ime) {
            self::assertNull($r->{$ime}, $ime);
        }
        // success je null, pa isSuccess() odlučuje po pfrBroj.
        self::assertTrue($r->isSuccess());
    }

    /** @trace R1 */
    public function testRezultatIzV111BezPfrBrojaNijeUspeh(): void
    {
        $serijal = str_replace('s:7:"pfrBroj";s:4:"AB-1";', 's:7:"pfrBroj";s:0:"";', self::REZULTAT_V111);
        self::assertNotSame(self::REZULTAT_V111, $serijal);

        $r = self::rezultat($serijal);
        self::assertSame('', $r->pfrBroj);
        self::assertFalse($r->isSuccess());
    }

    /** @trace R2 */
    public function testWebhookIzV111ZadrzavaStaraSvojstvaANovaSuNull(): void
    {
        $w = self::webhook(self::WEBHOOK_V111);

        self::assertSveInicijalizovano($w);
        self::assertSame('invoice.fiscalized', $w->event);
        self::assertSame('AB-1', $w->pfrBroj);
        self::assertSame(7, $w->racunId);
        self::assertSame(1190.5, $w->iznos);
        self::assertSame('success', $w->status);
        self::assertSame(['event' => 'invoice.fiscalized'], $w->rawData);
        foreach (self::NOVA_SVOJSTVA_WEBHOOKA as $ime) {
            self::assertNull($w->{$ime}, $ime);
        }
        self::assertTrue($w->isFiscalized());
        self::assertFalse($w->isFailed());
    }

    /**
     * Pravi dokaz između verzija: serijal napravljen klasama iz taga v1.1.1
     * (pod-proces, `git archive`) jednak je doslovnom i radna kopija ga čita.
     *
     * @trace R1 R2
     */
    public function testSerijalIzV111JeIstiKaoDoslovni(): void
    {
        $koren = dirname(__DIR__, 2);
        $dir = sys_get_temp_dir() . '/efisk-sdk-v111-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700), 'privremeni direktorijum');

        try {
            $arhiva = PodProces::izvrsi(['git', '-C', $koren, 'archive', '--output=' . $dir . '/src.tar', 'v1.1.1', 'src']);
            self::assertSame(0, $arhiva['kod'], 'git archive v1.1.1 (tag mora postojati): ' . $arhiva['greske']);
            $raspakivanje = PodProces::izvrsi(['tar', '-x', '-f', $dir . '/src.tar', '-C', $dir]);
            self::assertSame(0, $raspakivanje['kod'], $raspakivanje['greske']);
            self::assertFileExists($dir . '/src/DTO/FiskalizacijaResult.php');

            $skript = $dir . '/napravi.php';
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
                $r = new \Efiskalizacija\DTO\FiskalizacijaResult('AB-1', 'cXI=', 5, 'req-1', 'Uspesno');
                $w = new \Efiskalizacija\Webhook\WebhookPayload('invoice.fiscalized', 'AB-1', 7, 1190.5, 'success', ['event' => 'invoice.fiscalized']);
                echo (new \ReflectionClass($r))->getFileName(), "\n", serialize($r), "\n", serialize($w), "\n";
                // Povratak: objekti koje je serijalizovala 1.2.0 (argv[2]) kroz klase iz taga.
                $greske = [];
                set_error_handler(static function (int $n, string $m) use (&$greske): bool {
                    $greske[] = [$n, $m];
                    return true;
                });
                $nazad = unserialize(base64_decode($argv[2], true), ['allowed_classes' => [
                    \Efiskalizacija\DTO\FiskalizacijaResult::class, \Efiskalizacija\Webhook\WebhookPayload::class,
                ]]);
                restore_error_handler();
                echo json_encode([
                    'klase' => array_map('get_class', $nazad),
                    'rezultat' => [$nazad[0]->pfrBroj, $nazad[0]->qrCode, $nazad[0]->racunId, $nazad[0]->requestId, $nazad[0]->poruka],
                    'webhook' => [$nazad[1]->event, $nazad[1]->pfrBroj, $nazad[1]->racunId, $nazad[1]->iznos, $nazad[1]->status],
                    'greske' => $greske,
                ], JSON_THROW_ON_ERROR), "\n";
                PHP);
            $novi = serialize([
                new FiskalizacijaResult('AB-2', 'cXI=', 6, 'req-2', 'Uspesno', true, 'success', '1/2026', 'SHOP-9'),
                new WebhookPayload('invoice.fiscalized', 'AB-2', 8, 10.5, 'success', [], 'SHOP-9', '1/2026', true),
            ]);
            $izlaz = PodProces::izvrsi([PHP_BINARY, '-d', 'error_reporting=E_ALL', $skript, $dir . '/src', base64_encode($novi)]);
            self::assertSame(0, $izlaz['kod'], $izlaz['greske']);
            $redovi = explode("\n", rtrim($izlaz['izlaz'], "\n"));
            self::assertCount(4, $redovi, $izlaz['izlaz']);

            // README „Povratak na 1.1.x": radi, stara svojstva zadržavaju vrednost,
            // a na PHP ≥ 8.2 svako svojstvo dodato u 1.2.0 daje E_DEPRECATED.
            $povratak = json_decode($redovi[3], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame([FiskalizacijaResult::class, WebhookPayload::class], $povratak['klase']);
            self::assertSame(['AB-2', 'cXI=', 6, 'req-2', 'Uspesno'], $povratak['rezultat']);
            self::assertSame(['invoice.fiscalized', 'AB-2', 8, 10.5, 'success'], $povratak['webhook']);
            $ocekivano = [];
            if (PHP_VERSION_ID >= 80200) {
                foreach ([FiskalizacijaResult::class => self::NOVA_SVOJSTVA_REZULTATA, WebhookPayload::class => self::NOVA_SVOJSTVA_WEBHOOKA] as $klasa => $svojstva) {
                    foreach ($svojstva as $ime) {
                        $ocekivano[] = [E_DEPRECATED, "Creation of dynamic property {$klasa}::\${$ime} is deprecated"];
                    }
                }
            }
            self::assertEqualsCanonicalizing($ocekivano, $povratak['greske']);

            // Kontrola: pod-proces je učitao klasu iz taga, ne radnu kopiju.
            self::assertSame(realpath($dir . '/src/DTO/FiskalizacijaResult.php'), realpath($redovi[0]));
            self::assertSame(self::REZULTAT_V111, $redovi[1]);
            self::assertSame(self::WEBHOOK_V111, $redovi[2]);

            self::assertSveInicijalizovano(self::rezultat($redovi[1]));
            self::assertSveInicijalizovano(self::webhook($redovi[2]));
            self::assertTrue(self::rezultat($redovi[1])->isSuccess());
        } finally {
            foreach (['src.tar', 'napravi.php'] as $f) {
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

    /** Novi objekat kroz serialize() → unserialize() ostaje isti. */
    public function testNovRezultatIWebhookPrezivljavajuSerijalizaciju(): void
    {
        $r = new FiskalizacijaResult(
            'AB-1', 'cXI=', 5, 'req-1', 'Uspesno', true, 'success', '12/2026', 'SHOP-1', false,
            1190.5, '2026-11-15T10:00:00', 'https://suf.purs.gov.rs/v/?vl=x', '1522/1.2.0', 'KOPIJA', 42,
        );
        $kopija = self::rezultat(serialize($r));
        self::assertEquals($r, $kopija);
        foreach ((new \ReflectionClass($r))->getProperties() as $p) {
            self::assertSame($p->getValue($r), $p->getValue($kopija), $p->getName());
        }

        $w = new WebhookPayload(
            'invoice.failed', null, 9, 10.0, 'error', ['data' => ['success' => false]],
            'SHOP-2', '13/2026', false, '2026-11-15', 'Greska', '2026-11-15T10:00:00+01:00',
        );
        $kopijaW = self::webhook(serialize($w));
        foreach ((new \ReflectionClass($w))->getProperties() as $p) {
            self::assertSame($p->getValue($w), $p->getValue($kopijaW), $p->getName());
        }
    }

    /**
     * README: „Promene u 1.2.0" navodi svaki nov parametar konstruktora (iz
     * refleksije, posle onih iz 1.1.1) i nove metode, a „Nadogradnja na
     * 1.2.0" napominje da `unserialize()` starih objekata radi.
     */
    public function testReadmeNavodiNoveParametreIUnserialize(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        $promene = self::odeljak($readme, '### Promene u 1.2.0');
        $nadogradnja = self::odeljak($readme, '### Nadogradnja na 1.2.0');

        $pasus = strstr($promene, 'Potpisi postojećih javnih metoda');
        self::assertIsString($pasus, 'pasus o potpisima u „Promene u 1.2.0"');
        $pasus = (string) strstr($pasus, "\n- ", true) ?: $pasus;

        foreach ([FiskalizacijaResult::class => 5, WebhookPayload::class => 6] as $klasa => $staro) {
            $kratko = substr($klasa, (int) strrpos($klasa, '\\') + 1);
            self::assertStringContainsString("`{$kratko}` (", $pasus);
            $parametri = (new \ReflectionMethod($klasa, '__construct'))->getParameters();
            self::assertGreaterThan($staro, count($parametri));
            foreach (array_slice($parametri, $staro) as $p) {
                self::assertTrue($p->isDefaultValueAvailable(), $p->getName());
                self::assertNull($p->getDefaultValue(), $p->getName());
                self::assertStringContainsString('`' . $p->getName() . '`', $pasus, "{$kratko}::{$p->getName()}");
            }
        }
        self::assertStringContainsString('`FiskalizacijaResult::isSuccess()`', $pasus);
        self::assertStringContainsString('`Invoice::setAdvertisementText()`', $pasus);
        foreach ([FiskalizacijaResult::class, WebhookPayload::class] as $klasa) {
            $metoda = new \ReflectionMethod($klasa, '__unserialize');
            self::assertSame($klasa, $metoda->getDeclaringClass()->getName());
            self::assertTrue($metoda->isPublic());
            $kratko = substr($klasa, (int) strrpos($klasa, '\\') + 1);
            self::assertStringContainsString("`{$kratko}::__unserialize()`", $pasus);
        }

        // Stavka o starim objektima: imenice I polaritet (radi, a ne „ne radi").
        $stavka = self::stavka($nadogradnja, '`unserialize()`');
        self::assertStringContainsString('`serialize()`', $stavka);
        self::assertMatchesRegularExpression('/`unserialize\(\)` sa 1\.2\.0 radi\b/u', $stavka);
        self::assertDoesNotMatchRegularExpression('/\b(ne radi|nije podržan|ne podržava|ne može|baca)\b/iu', $stavka);

        // Povratak 1.2.0 → 1.1.x: radi, uz E_DEPRECATED po novom svojstvu na PHP ≥ 8.2
        // (izvršeno u testSerijalIzV111JeIstiKaoDoslovni).
        $povratak = strstr($nadogradnja, 'Povratak na 1.1.x');
        self::assertIsString($povratak, 'pasus o povratku na 1.1.x u „Nadogradnja na 1.2.0"');
        $povratak = (string) preg_replace('/\s+/u', ' ', $povratak);
        self::assertMatchesRegularExpression('/\APovratak na 1\.1\.x \([^)\n]*\) radi:/u', $povratak);
        self::assertDoesNotMatchRegularExpression('/\b(ne radi|nije podržan|ne može)\b/iu', $povratak);
        foreach (['PHP ≥ 8.2', 'E_DEPRECATED', '„Creation of dynamic property“'] as $deo) {
            self::assertStringContainsString($deo, $povratak);
        }
        self::assertStringContainsString(count(self::NOVA_SVOJSTVA_REZULTATA) . ' kod `FiskalizacijaResult`', $povratak);
        self::assertStringContainsString(count(self::NOVA_SVOJSTVA_WEBHOOKA) . ' kod `WebhookPayload`', $povratak);
    }

    /** Stavka liste (`- ` do sledeće stavke ili praznog reda) koja sadrži $deo; tačno jedna. */
    private static function stavka(string $odeljak, string $deo): string
    {
        preg_match_all('/^- .*?(?=^- |^\s*$|\z)/msu', $odeljak, $m);
        $nadjene = array_values(array_filter($m[0], static fn (string $s): bool => str_contains($s, $deo)));
        self::assertCount(1, $nadjene, "tačno jedna stavka sa {$deo}");

        // Prelom reda ne sme sakriti „ne radi“ od provere polariteta.
        return (string) preg_replace('/\s+/u', ' ', $nadjene[0]);
    }

    private static function odeljak(string $tekst, string $naslov): string
    {
        $pocetak = strpos($tekst, $naslov . "\n");
        self::assertNotFalse($pocetak, $naslov);
        $kraj = strpos($tekst, "\n### ", $pocetak + strlen($naslov));

        return $kraj === false ? substr($tekst, $pocetak) : substr($tekst, $pocetak, $kraj - $pocetak);
    }

    /** Nepoznat ključ (npr. iz novije verzije) ne pravi dinamičko svojstvo. */
    public function testNepoznatKljucSeIgnorise(): void
    {
        $serijal = str_replace(
            ':5:{s:7:"pfrBroj"',
            ':6:{s:11:"buducePolje";s:1:"x";s:7:"pfrBroj"',
            self::REZULTAT_V111,
        );
        self::assertNotSame(self::REZULTAT_V111, $serijal);

        $greske = [];
        set_error_handler(static function (int $n, string $poruka) use (&$greske): bool {
            $greske[] = $poruka;

            return true;
        });
        try {
            $r = self::rezultat($serijal);
        } finally {
            restore_error_handler();
        }

        self::assertSame([], $greske);
        self::assertSame('AB-1', $r->pfrBroj);
        self::assertArrayNotHasKey('buducePolje', get_object_vars($r));
    }
}
