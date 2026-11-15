<?php

declare(strict_types=1);

// Pod-proces za TajnaUTraguZahtevaTest: pokreće se sa
// `-d zend.exception_ignore_args=0 -d zend.exception_string_param_max_len=1000000`,
// pa trag izuzetka čuva argumente u punoj dužini.
//
// Svaki slučaj ide kroz javnu metodu SDK-a i PRAVI `CurlHttpClient` ka
// zatvorenom portu (`NetworkException` iz `request()`), sa fiksnim satom pa
// je potpis poznat unapred. Za svaki slučaj ispisuje da li se API ključ,
// potpis ili telo (marker) vide u `getTrace()` i u `(string) $e`, i da li trag
// uopšte ima okvir `CurlHttpClient::request` sa 4 argumenta (inače je provera
// slepa). Slučaj „kontrola" baca iz funkcije BEZ `#[\SensitiveParameter]` sa
// istim vrednostima i mora da pokaže sve tri.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Efiskalizacija\Api\EmailApi;
use Efiskalizacija\Api\FiskalizacijaApi;
use Efiskalizacija\Api\InvoiceApi;
use Efiskalizacija\Api\PdfApi;
use Efiskalizacija\Api\StatusApi;
use Efiskalizacija\Api\TestApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\Enum\PaymentType;
use Efiskalizacija\Http\CurlHttpClient;

const KLJUC = 'efisk_1_KLJUC-0123456789abcdefghijklmnop';
const TAJNA = 'TAJNA-0123456789abcdefghijklmnop';
const MARKER = 'MARKER-TELA-0123456789abcdefghijklmnop';
const VREME = 1705000000;

$s = stream_socket_server('tcp://127.0.0.1:0');
$baza = 'http://' . stream_socket_get_name($s, false);
fclose($s);

$config = new Config(KLJUC, TAJNA, $baza, timeout: 1, connectTimeout: 1, maxRetries: 0);
$napravi = static fn (string $klasa) => new $klasa(
    $config,
    new CurlHttpClient(1, 1),
    new HmacSigner(TAJNA),
    null,
    static fn (): int => VREME,
);

/** Potpis v2 izračunat nezavisno od SDK-a. */
function potpis(string $metoda, string $cilj, string $telo): string
{
    return base64_encode(hash_hmac('sha256', 'v2:' . VREME . $metoda . $cilj . hash('sha256', $telo), TAJNA, true));
}

function kontrolaBezAtributa(array $headers, string $body): void
{
    throw new \RuntimeException('kontrola ' . count($headers) . strlen($body));
}

$racun = Invoice::create()
    ->setPaymentType(PaymentType::Kartica)
    ->setNote(MARKER)
    ->addItem(new InvoiceItem(naziv: 'Stavka', kolicina: 1, jedinicnaCena: 100, pdvStopa: 20));
$teloRacuna = (string) json_encode($racun->toArray(), JSON_UNESCAPED_UNICODE);
$teloMejla = (string) json_encode(['pfr' => 'P-1', 'email' => MARKER . '@primer.rs'], JSON_UNESCAPED_UNICODE);
$p = '/api/multitenant.php';

// ime => [poziv, potpis, marker tela ili null kad zahtev nema telo]
$slucajevi = [
    'kontrola' => [
        static fn () => kontrolaBezAtributa(['X-API-Key' => KLJUC, 'X-Signature' => potpis('POST', "{$p}/fiskalizacija", $teloRacuna)], $teloRacuna),
        potpis('POST', "{$p}/fiskalizacija", $teloRacuna),
        MARKER,
    ],
    'fiskalizuj' => [static fn () => $napravi(FiskalizacijaApi::class)->fiskalizuj($racun), potpis('POST', "{$p}/fiskalizacija", $teloRacuna), MARKER],
    'send' => [static fn () => $napravi(EmailApi::class)->send('P-1', MARKER . '@primer.rs'), potpis('POST', "{$p}/send-email", $teloMejla), MARKER],
    'run' => [static fn () => $napravi(TestApi::class)->run(), potpis('POST', "{$p}/test", ''), null],
    'fetch' => [static fn () => $napravi(StatusApi::class)->fetch(), potpis('GET', "{$p}/status", ''), null],
    'list' => [static fn () => $napravi(InvoiceApi::class)->list(5, 0), potpis('GET', "{$p}/invoice?limit=5&offset=0", ''), null],
    'download' => [static fn () => $napravi(PdfApi::class)->download('P-1'), potpis('GET', "{$p}/pdf?pfr=P-1&download=1", ''), null],
    'downloadToFile' => [static fn () => $napravi(PdfApi::class)->downloadToFile('P-1', '/nepostojeci/dir/x.pdf'), potpis('GET', "{$p}/pdf?pfr=P-1&download=1", ''), null],
];

$rezultat = [
    'ini_ignore_args' => (string) ini_get('zend.exception_ignore_args'),
    'php' => PHP_VERSION_ID,
    'slucajevi' => [],
];

foreach ($slucajevi as $ime => [$slucaj, $potpis, $marker]) {
    try {
        $slucaj();
        $rezultat['slucajevi'][$ime] = ['baceno' => false];
    } catch (\Throwable $e) {
        $okvir = null;
        foreach ($e->getTrace() as $f) {
            if (($f['class'] ?? null) === CurlHttpClient::class && $f['function'] === 'request') {
                $okvir = $f;
            }
        }
        $trag = print_r($e->getTrace(), true) . var_export(array_column($e->getTrace(), 'args'), true);
        $tekst = (string) $e . $e->getTraceAsString();
        $rezultat['slucajevi'][$ime] = [
            'baceno' => true,
            'klasa' => $e::class,
            'okvir_request_args' => $okvir === null ? null : count($okvir['args'] ?? []),
            'kljuc_u_tragu' => str_contains($trag, KLJUC),
            'potpis_u_tragu' => str_contains($trag, $potpis),
            'telo_u_tragu' => $marker !== null && str_contains($trag, $marker),
            'kljuc_u_tekstu' => str_contains($tekst, KLJUC),
            'potpis_u_tekstu' => str_contains($tekst, $potpis),
            'telo_u_tekstu' => $marker !== null && str_contains($tekst, $marker),
            'ima_telo' => $marker !== null,
        ];
    }
}

echo json_encode($rezultat, JSON_THROW_ON_ERROR);
