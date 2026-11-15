<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\AbstractApi;
use Efiskalizacija\Config;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use PHPUnit\Framework\TestCase;

/**
 * README i docblock-ovi prate pravilo ponavljanja iz 1.2.0 (neobjavljeno):
 * zaštita od duplog računa je `broj_racuna` (`setInvoiceNumber()`),
 * `setIdempotencyKey()` je zastareo i ne šalje se, a tabela ponavljanja u
 * README je izvedena iz konstanti `AbstractApi` (promena konstante bez
 * izmene README obara test).
 */
final class Verzija120DokumentacijaTest extends TestCase
{
    private static function fajl(string $relativno): string
    {
        $putanja = dirname(__DIR__, 2) . '/' . $relativno;
        self::assertFileExists($putanja);

        return (string) file_get_contents($putanja);
    }

    private static function odeljak(string $naslov, string $sledeci): string
    {
        $readme = self::fajl('README.md');
        $pocetak = strpos($readme, $naslov);
        self::assertNotFalse($pocetak, $naslov);
        $kraj = strpos($readme, $sledeci, $pocetak + strlen($naslov));
        self::assertNotFalse($kraj, $sledeci);

        return substr($readme, $pocetak, $kraj - $pocetak);
    }

    /** @return list<int> */
    private static function konstanta(string $ime): array
    {
        $vrednost = (new \ReflectionClassConstant(AbstractApi::class, $ime))->getValue();
        self::assertIsArray($vrednost);

        return $vrednost;
    }

    /** @trace R5 */
    public function testIdempotencyKeyJeZastareoIUpucujeNaBrojRacuna(): void
    {
        foreach (['setIdempotencyKey', 'getIdempotencyKey'] as $metoda) {
            $doc = (string) (new \ReflectionMethod(Invoice::class, $metoda))->getDocComment();
            self::assertMatchesRegularExpression('/@deprecated\b/', $doc, $metoda);
            self::assertStringContainsString('setInvoiceNumber()', $doc, $metoda);
            self::assertStringContainsString('ne šalje', $doc, $metoda);
            self::assertStringNotContainsString('sprecava duplu', mb_strtolower($doc), $metoda);
        }
    }

    /**
     * Ključ i dalje radi kao pre (bez loma) i namerno se NE šalje serveru.
     *
     * @trace R5
     */
    public function testIdempotencyKeySeNeSaljeServeru(): void
    {
        $racun = Invoice::create()
            ->addItem(new InvoiceItem(naziv: 'X', kolicina: 1, jedinicnaCena: 1, pdvStopa: 20))
            ->setIdempotencyKey('KLJUC-IDEMPOTENCIJE');

        self::assertSame('KLJUC-IDEMPOTENCIJE', $racun->getIdempotencyKey());
        self::assertStringNotContainsString('KLJUC-IDEMPOTENCIJE', (string) json_encode($racun->toArray()));
    }

    /** @trace R5 */
    public function testReadmeIdempotencyUpucujeNaBrojRacuna(): void
    {
        $odeljak = self::odeljak("## Idempotency", "\n## Error handling\n");

        self::assertStringContainsString('setInvoiceNumber(', $odeljak);
        self::assertStringContainsString('`broj_racuna`', $odeljak);
        self::assertStringContainsString('`setIdempotencyKey()`', $odeljak);
        self::assertStringContainsString('zastarel', $odeljak);
        self::assertStringContainsString('ne šalje serveru', $odeljak);
        self::assertStringNotContainsString('Sprečava duplu fiskalizaciju istog računa', $odeljak);
    }

    /** @trace R6 */
    public function testOdeljakPromenaU120JeIznad111INeobjavljen(): void
    {
        $readme = self::fajl('README.md');
        $novi = strpos($readme, "### Promene u 1.2.0 (neobjavljeno)\n");
        $stari = strpos($readme, "### Promene u 1.1.1\n");
        self::assertNotFalse($novi, 'odeljak „Promene u 1.2.0 (neobjavljeno)"');
        self::assertNotFalse($stari);
        self::assertLessThan($stari, $novi);
        // Objavljena verzija ostaje 1.1.1 dok se 1.2.0 ne objavi
        self::assertStringContainsString('Trenutna verzija: **1.1.1**', $readme);

        $odeljak = substr($readme, $novi, $stari - $novi);
        foreach (['`broj_racuna`', '502', '504', 'wasRequestSent()', '#[\SensitiveParameter]', 'CurlHttpClient', '`@deprecated`', 'WARNING'] as $deo) {
            self::assertStringContainsString($deo, $odeljak, $deo);
        }
    }

    /**
     * Tabela u „Retry logika": jedan red po statusu, kolone „GET i POST
     * /fiskalizacija sa brojem" i „ostali zahtevi". Statusi i Da/Ne su
     * izvedeni iz `RETRYABLE_STATUS_CODES` i `RETRYABLE_BEFORE_PROCESSING`.
     *
     * @trace R6
     */
    public function testTabelaPonavljanjaJeIzvedenaIzKonstanti(): void
    {
        $odeljak = self::odeljak("## Retry logika\n", "\n## Webhook\n");
        preg_match_all('/^\| (\d{3})\b[^|\n]*\| (Da|Ne) +\| (Da|Ne) +\|$/m', $odeljak, $redovi, PREG_SET_ORDER);
        $tabela = [];
        foreach ($redovi as [, $status, $bezbedan, $ostali]) {
            self::assertArrayNotHasKey((int) $status, $tabela, "status {$status} jednom");
            $tabela[(int) $status] = [$bezbedan, $ostali];
        }

        $ponavljani = self::konstanta('RETRYABLE_STATUS_CODES');
        $preObrade = self::konstanta('RETRYABLE_BEFORE_PROCESSING');
        self::assertNotEmpty($preObrade);
        foreach ($ponavljani as $status) {
            self::assertSame(['Da', in_array($status, $preObrade, true) ? 'Da' : 'Ne'], $tabela[$status] ?? null, "status {$status}");
        }
        foreach ([400, 401, 403, 422] as $status) {
            self::assertSame(['Ne', 'Ne'], $tabela[$status] ?? null, "status {$status}");
        }
        self::assertSame([], array_diff(array_keys($tabela), $ponavljani, [400, 401, 403, 422]), 'nema drugih statusa');

        self::assertMatchesRegularExpression('/^\| Mrežna greška, zahtev nije poslat[^|\n]*\| Da +\| Da +\|$/m', $odeljak);
        self::assertMatchesRegularExpression('/^\| Mrežna greška, ishod nepoznat[^|\n]*\| Da +\| Ne +\|$/m', $odeljak);
    }

    /**
     * Pozitivna kontrola čitača tabele: vidi red i razlikuje kolone.
     *
     * @trace R6
     */
    public function testCitacTabeleVidiRedove(): void
    {
        $mamac = "| 502 (Bad Gateway) | Da | Ne |\n| 429 (x) | Da  | Da  |\n| 50 nije status | Da | Da |\n";
        preg_match_all('/^\| (\d{3})\b[^|\n]*\| (Da|Ne) +\| (Da|Ne) +\|$/m', $mamac, $redovi, PREG_SET_ORDER);

        self::assertSame([['502', 'Da', 'Ne'], ['429', 'Da', 'Da']], array_map(static fn (array $r): array => array_slice($r, 1), $redovi));
    }

    /**
     * Rečenice proze README. Rečenica se završava tačkom posle koje sledi
     * razmak ili praznim redom (tačka iza „npr" ne završava rečenicu); blokovi
     * koda (```) i redovi tabele (`| ...`) nisu proza i izostavljaju se;
     * razmaci se spajaju.
     *
     * @return list<string>
     */
    private static function recenice(string $tekst): array
    {
        $tekst = (string) preg_replace('/^[ \t]*```.*?^[ \t]*```[ \t]*$/msu', '', $tekst);
        $recenice = [];
        foreach (preg_split('/\n[ \t]*\n/u', $tekst) ?: [] as $pasus) {
            $proza = (string) preg_replace('/^[ \t]*\|.*$/mu', '', $pasus);
            $proza = (string) preg_replace('/\s+/u', ' ', $proza);
            foreach (preg_split('/(?<!\bnpr)\.(?=\s|$)/u', $proza) ?: [] as $recenica) {
                $recenice[] = $recenica;
            }
        }

        return $recenice;
    }

    /**
     * Rečenice README koje govore „pre obrade".
     *
     * @return list<string>
     */
    private static function recenicePreObrade(string $tekst): array
    {
        return array_values(array_filter(self::recenice($tekst), static fn (string $r): bool => str_contains($r, 'pre obrade')));
    }

    /**
     * Trocifreni HTTP kodovi (1xx–5xx) u rečenici, sortirani i bez ponavljanja.
     *
     * @return list<int>
     */
    private static function kodovi(string $recenica): array
    {
        preg_match_all('/(?<!\d)[1-5]\d\d(?!\d)/u', $recenica, $pogoci);
        $kodovi = array_values(array_unique(array_map('intval', $pogoci[0])));
        sort($kodovi);

        return $kodovi;
    }

    /**
     * Rečenice koje kažu da SDK zahtev NE ponavlja (izuzetak, „ne ponavlja",
     * „ne ponovi", „tačno jednom") i pominju bar jedan kod iz
     * `RETRYABLE_STATUS_CODES`, uz skup svih kodova u rečenici.
     *
     * @param list<int> $ponavljani
     * @return list<array{string, list<int>}>
     */
    private static function receniceBezPonavljanja(string $tekst, array $ponavljani): array
    {
        $nadjene = [];
        foreach (self::recenice($tekst) as $recenica) {
            $kodovi = self::kodovi($recenica);
            if (array_intersect($kodovi, $ponavljani) === []) {
                continue;
            }
            if (preg_match('/izuzetak|\bne ponavlja|\bne ponovi|tačno jednom/iu', $recenica) === 1) {
                $nadjene[] = [$recenica, $kodovi];
            }
        }

        return $nadjene;
    }

    /**
     * U odeljcima „Idempotency", „Promene u 1.2.0" i „Nadogradnja na 1.2.0"
     * svaka rečenica koja kaže da SDK zahtev bez broja ne ponavlja nabraja
     * tačno statuse `RETRYABLE_STATUS_CODES` koji nisu u
     * `RETRYABLE_BEFORE_PROCESSING` (izostavljen ili dodat kod obara test).
     *
     * @trace R2 R6
     */
    public function testReceniceBezPonavljanjaNabrajajuRazlikuKonstanti(): void
    {
        $ponavljani = self::konstanta('RETRYABLE_STATUS_CODES');
        $ocekivano = array_values(array_diff($ponavljani, self::konstanta('RETRYABLE_BEFORE_PROCESSING')));
        sort($ocekivano);
        self::assertNotEmpty($ocekivano);

        $odeljci = [
            'Idempotency' => self::odeljak("## Idempotency\n", "\n## Error handling\n"),
            'Promene u 1.2.0' => self::odeljak("### Promene u 1.2.0 (neobjavljeno)\n", "\n### Promene u 1.1.1\n"),
            'Nadogradnja na 1.2.0' => self::odeljak("### Nadogradnja na 1.2.0\n", "\n### Nadogradnja sa 1.0.x\n"),
        ];
        foreach ($odeljci as $naslov => $odeljak) {
            $recenice = self::receniceBezPonavljanja($odeljak, $ponavljani);
            self::assertNotEmpty($recenice, "{$naslov}: rečenica o statusima koji se ne ponavljaju");
            foreach ($recenice as [$recenica, $kodovi]) {
                self::assertSame($ocekivano, $kodovi, "{$naslov}: {$recenica}");
            }
        }
    }

    /**
     * Kontrola detektora u oba smera: izostavljen kod se vidi (i preko
     * preloma reda), a rečenica bez reči o ne-ponavljanju, red tabele, blok
     * koda i rečenica bez koda iz konstante se ne broje.
     *
     * @trace R6
     */
    public function testDetektorRecenicaBezPonavljanjaVidiObaSmera(): void
    {
        $mamac = "Šalju se tačno\n  jednom: posle 502 i 504. Na 429 i 503 se ponavljaju. 503 vraća i Apache.\n"
            . "SDK ne ponavlja 400 ni 422.\n\n| 503 | izuzetak | Ne |\n\n```php\n// 503 baca izuzetak\n```\n\n"
            . "Bez broja (npr. 503, 502, 504, 502) dobijate izuzetak.";
        $recenice = self::receniceBezPonavljanja($mamac, [429, 502, 503, 504]);

        self::assertSame(
            [['Šalju se tačno jednom: posle 502 i 504', [502, 504]], ['Bez broja (npr. 503, 502, 504, 502) dobijate izuzetak', [502, 503, 504]]],
            array_map(static fn (array $r): array => [trim($r[0]), $r[1]], $recenice),
        );
        self::assertNotSame([502, 503, 504], $recenice[0][1]);
        self::assertSame([], self::kodovi('verzija 1.2.0, broj 001, godina 2026, kod 5030'));
    }

    /**
     * Statusi koji se ponavljaju samo za bezbedan zahtev (u
     * `RETRYABLE_STATUS_CODES`, a nisu u `RETRYABLE_BEFORE_PROCESSING`) se
     * nigde u README ne pominju u rečenici koja govori „pre obrade" — npr.
     * „Na 429 i 503 (server ih vraća pre obrade)" pada kad 503 nije u
     * konstanti.
     *
     * @trace R2 R6
     */
    public function testReadmeNePripisujeObradiStatuseVanKonstante(): void
    {
        $samoBezbedni = array_diff(self::konstanta('RETRYABLE_STATUS_CODES'), self::konstanta('RETRYABLE_BEFORE_PROCESSING'));
        self::assertNotEmpty($samoBezbedni);
        $recenice = self::recenicePreObrade(self::fajl('README.md'));
        self::assertNotEmpty($recenice, 'README pominje „pre obrade"');

        foreach ($recenice as $recenica) {
            foreach ($samoBezbedni as $status) {
                self::assertDoesNotMatchRegularExpression("/(?<!\\d){$status}(?!\\d)/", $recenica, "status {$status}: {$recenica}");
            }
        }
    }

    /**
     * Kontrola detektora u oba smera: rečenica „429 i 503 ... pre obrade"
     * se vidi i obara, a tačna rečenica o 503 bez „pre obrade", broj 5030 i
     * red tabele sa 503 ispred pasusa „pre obrade" ne obaraju.
     *
     * @trace R6
     */
    public function testDetektorRecenicaPreObradeVidiObaSmera(): void
    {
        $mamac = "Na 429 i\n  503 (server ih vraća pre obrade) ponavljaju se. 503 vraća i Apache posle obrade\nzahteva. Kod 5030 nije status pre obrade.\n\n"
            . "Uvod bez tačke:\n\n| 503 (Unavailable) | Da | Ne |\n\n429 server vraća pre obrade";
        $recenice = self::recenicePreObrade($mamac);

        self::assertSame(['Na 429 i 503 (server ih vraća pre obrade) ponavljaju se', ' Kod 5030 nije status pre obrade', '429 server vraća pre obrade'], $recenice);
        self::assertMatchesRegularExpression('/(?<!\d)503(?!\d)/', $recenice[0]);
        self::assertDoesNotMatchRegularExpression('/(?<!\d)503(?!\d)/', $recenice[1]);
    }

    /** @trace R6 */
    public function testPodrazumevaniBrojPonavljanjaJeIzKonfiguracije(): void
    {
        $odeljak = self::odeljak("## Retry logika\n", "\n## Webhook\n");
        $n = Config::DEFAULT_MAX_RETRIES;

        self::assertStringContainsString("početni zahtev + do {$n} ponavljanja", $odeljak);
        self::assertDoesNotMatchRegularExpression('/\b' . $n . ' retry-a\b/', $odeljak);
        self::assertStringNotContainsString("{$n} pokušaja", self::fajl('README.md'));
    }

    /**
     * Bezuslovna garancija broja računa („ne može da napravi drugi dokument",
     * „bezbedno ponoviti", „vraća već izdat račun"). Server prepoznaje samo
     * već UPISAN uspešan račun (`ClientReferenceGuard::findExistingSuccess`),
     * a fiskalizuje pre upisa: obrada koja se prekine ili ne uspe posle
     * fiskalizacije (pad servera, istek odgovora Poreske uprave) ostavlja
     * račun bez zapisa, pa ponovljen zahtev sa istim brojem izdaje drugi.
     * Obuhvata i obrnut polaritet posledice („ne izdaje drugi", „ne pravi
     * drugi", „Prozora nema", „Prozor ne ostaje", „je potpuno zaštićen") i
     * umanjen prozor („jedan prozor", „Jedan uzak prozor").
     */
    private const BEZUSLOVNA_GARANCIJA = '/ne može da napravi drug|\bbezbedno ponovi|vraća već izdat|ne izdaje nov račun|ne može da izda drug'
        . '|\bne izdaje drug|\bne pravi drug|\bprozora nema|\bprozor ne ostaje|\bje potpuno zaštićen|\bjedan (uzak )?prozor/iu';

    /** Docblock bez zvezdica na početku reda, razmaci spojeni. */
    private static function docblock(string $klasa, string $metoda): string
    {
        $doc = (string) (new \ReflectionMethod($klasa, $metoda))->getDocComment();
        self::assertNotSame('', $doc, "{$klasa}::{$metoda}");

        return self::spojeno((string) preg_replace('/^[ \t]*\/?\*+\/?[ \t]?/mu', '', $doc));
    }

    private static function spojeno(string $tekst): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $tekst));
    }

    /**
     * Tekstovi koji opisuju zaštitu broja računa i fraze koje svaki mora da
     * nosi: šta server stvarno prepoznaje, preostali prozor (obrada koja se
     * prekine ili ne uspe posle fiskalizacije), njegovu posledicu („izdaje
     * drugi račun"), da se takav račun ne vidi u listi ni u admin panelu i
     * da se, kad ishod ostane nepoznat a provera ne nađe račun, pre novog
     * slanja obraća podršci.
     *
     * Granica: obavezne fraze i {@see self::BEZUSLOVNA_GARANCIJA} ne čitaju
     * smisao. Tekst koji zadrži sve fraze, a doda suprotnu rečenicu
     * sročenu van liste zabranjenih („SDK to rešava sam"), prolazi.
     *
     * @return array<string, array{string, list<string>}>
     */
    private static function tekstoviOGaranciji(): array
    {
        $prozor = ['ne uspe posle fiskalizacije', 'izdaje drugi račun'];
        $nevidljiv = ['server nije upisao', 'ne vidi ni u `invoices()->list()` ni u admin panelu'];
        $provera = ['posle poslednjeg pokušaja', 'sa brojem računa ili bez njega', 'podršci'];

        return [
            'README Idempotency' => [
                self::spojeno(self::odeljak("## Idempotency\n", "\n## Error handling\n")),
                ['već upisan račun sa istim brojem', ...$prozor, ...$nevidljiv, ...$provera, 'proverite'],
            ],
            'README Promene u 1.2.0' => [
                self::spojeno(self::odeljak("### Promene u 1.2.0 (neobjavljeno)\n", "\n### Promene u 1.1.1\n")),
                ['već upisan račun sa istim brojem', ...$prozor],
            ],
            'README Retry logika' => [
                self::spojeno(self::odeljak("## Retry logika\n", "\n## Webhook\n")),
                [...$prozor, ...$nevidljiv, ...$provera, 'proverite'],
            ],
            'AbstractApi::requestRawWithRetry' => [
                self::docblock(AbstractApi::class, 'requestRawWithRetry'),
                [...$prozor, ...$provera, 'proverava'],
            ],
            'AbstractApi::isSafeToRepeat' => [
                self::docblock(AbstractApi::class, 'isSafeToRepeat'),
                ['već upisan račun sa istim brojem', ...$prozor],
            ],
            'Invoice::setIdempotencyKey' => [
                self::docblock(Invoice::class, 'setIdempotencyKey'),
                ['već upisan račun sa istim brojem', ...$prozor],
            ],
        ];
    }

    /**
     * Zaštita broja računa nije bezuslovna: README i docblock-ovi kažu šta
     * server prepoznaje (već upisan račun), imenuju prozor (obrada koja se
     * prekine ili ne uspe posle fiskalizacije) i njegovu posledicu (drugi
     * račun, nevidljiv u listi i admin panelu) i nalažu proveru ishoda kad
     * ostane nepoznat, sa brojem ili bez njega, pa podršku kad je provera ne
     * nađe.
     *
     * @trace R5 R6
     */
    public function testGarancijaBrojaRacunaNijeBezuslovna(): void
    {
        self::assertDoesNotMatchRegularExpression(self::BEZUSLOVNA_GARANCIJA, self::spojeno(self::fajl('README.md')), 'README');
        foreach (self::tekstoviOGaranciji() as $opis => [$tekst, $fraze]) {
            self::assertDoesNotMatchRegularExpression(self::BEZUSLOVNA_GARANCIJA, $tekst, $opis);
            foreach ($fraze as $fraza) {
                self::assertStringContainsString($fraza, $tekst, "{$opis}: „{$fraza}\"");
            }
        }
    }

    /**
     * Pozitivna kontrola stražara: stare bezuslovne rečenice (i preko
     * preloma reda i zvezdica docblock-a) se vide, a tačna formulacija ne.
     *
     * @trace R6
     */
    public function testStrazarBezuslovneGarancijeVidiObaSmera(): void
    {
        foreach (
            [
                "SDK sam ponavlja samo zahtev koji ne\n  može da napravi drugi dokument",
                'a i vi možete bezbedno ponoviti isti poziv sa istim brojem',
                '(server ga prepoznaje i vraća već izdat račun)',
                'za broj koji je već uspešno fiskalizovan server ne izdaje nov račun',
                'Ostaje jedan prozor: server račun fiskalizuje pre nego što ga upiše',
                'Jedan uzak prozor ostaje',
                'Prozora nema: ponovljen zahtev sa istim brojem ne pravi drugi račun',
                "Bezbedan zahtev je potpuno\n  zaštićen: server vraća isti račun",
                'Prozor ne ostaje kad se obrada prekine posle fiskalizacije',
                'tada ponovljen zahtev ne izdaje drugi račun',
            ] as $staro
        ) {
            self::assertMatchesRegularExpression(self::BEZUSLOVNA_GARANCIJA, self::spojeno($staro), $staro);
        }

        $doc = self::spojeno((string) preg_replace(
            '/^[ \t]*\/?\*+\/?[ \t]?/mu',
            '',
            "/**\n     * Da li ponovljen zahtev ne\n     * može da napravi drugi dokument\n     */",
        ));
        self::assertSame('Da li ponovljen zahtev ne može da napravi drugi dokument', $doc);
        self::assertMatchesRegularExpression(self::BEZUSLOVNA_GARANCIJA, $doc);

        self::assertDoesNotMatchRegularExpression(
            self::BEZUSLOVNA_GARANCIJA,
            'server prepoznaje već upisan račun sa istim brojem i vraća ga umesto novog. Zahtev je bezbedan za ponavljanje kad je GET',
        );
        self::assertDoesNotMatchRegularExpression(
            self::BEZUSLOVNA_GARANCIJA,
            'Ni bezbedan zahtev nije potpuno zaštićen: kad se obrada na serveru prekine ili ne uspe posle fiskalizacije, '
                . 'server ga nije upisao, pa ponovljen zahtev sa istim brojem izdaje drugi račun. Zaštita ima prozor',
        );
    }

    /** @trace R6 */
    public function testReadmeNeTvrdiInterniRequestId(): void
    {
        self::assertStringNotContainsString('RequestId', self::fajl('README.md'));
        self::assertStringNotContainsString('(retry automatski)', self::fajl('README.md'));
    }
}
