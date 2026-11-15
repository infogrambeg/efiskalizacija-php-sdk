<?php

declare(strict_types=1);

// Pod-proces za TajnaUTraguTest: pokreće se sa
// `-d zend.exception_ignore_args=0 -d zend.exception_string_param_max_len=1000000`,
// pa trag izuzetka čuva argumente u punoj dužini. Za svaki slučaj ispisuje da
// li se API ključ ili tajna vide u `getTrace()` i u `(string) $e`.
// Slučaj „kontrola" baca iz funkcije BEZ `#[\SensitiveParameter]` i mora da
// pokaže obe vrednosti — inače je test slep (npr. ini je ipak sakrio argumente).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Efiskalizacija\Config;
use Efiskalizacija\EfiskalizacijaClient;

const TAJNA = 'TAJNA-0123456789abcdefghijklmnop';
const KLJUC = 'KLJUC-0123456789abcdefghijklmnop';

function kontrolaBezAtributa(string $apiKey, string $apiSecret): void
{
    throw new \InvalidArgumentException('kontrola ' . strlen($apiKey . $apiSecret));
}

$slucajevi = [
    'kontrola' => static fn () => kontrolaBezAtributa(KLJUC, TAJNA),
    'Config maxRetries 11' => static fn () => new Config(KLJUC, TAJNA, maxRetries: 11),
    'Config maxRetries -1' => static fn () => new Config(KLJUC, TAJNA, maxRetries: -1),
    'Config retryBaseDelayMs -1' => static fn () => new Config(KLJUC, TAJNA, retryBaseDelayMs: -1),
    'Config retryMultiplier 0' => static fn () => new Config(KLJUC, TAJNA, retryMultiplier: 0),
    'Config prazan kljuc' => static fn () => new Config('', TAJNA),
    'Config prazna tajna' => static fn () => new Config(KLJUC, ''),
    'Config::sandbox prazan kljuc' => static fn () => Config::sandbox('', TAJNA),
    'Config::production prazan kljuc' => static fn () => Config::production('', TAJNA),
    'Config::sandbox prazna tajna' => static fn () => Config::sandbox(KLJUC, ''),
    'EfiskalizacijaClient::create prazan kljuc' => static fn () => EfiskalizacijaClient::create('', TAJNA),
    'EfiskalizacijaClient::sandbox prazan kljuc' => static fn () => EfiskalizacijaClient::sandbox('', TAJNA),
    'EfiskalizacijaClient::create prazna tajna' => static fn () => EfiskalizacijaClient::create(KLJUC, ''),
];

$rezultat = [
    'ini_ignore_args' => (string) ini_get('zend.exception_ignore_args'),
    'slucajevi' => [],
];

foreach ($slucajevi as $ime => $slucaj) {
    try {
        $slucaj();
        $rezultat['slucajevi'][$ime] = ['baceno' => false];
    } catch (\Throwable $e) {
        $trag = print_r($e->getTrace(), true) . var_export(array_column($e->getTrace(), 'args'), true);
        $tekst = (string) $e . $e->getTraceAsString();
        $rezultat['slucajevi'][$ime] = [
            'baceno' => true,
            'klasa' => $e::class,
            'tajna_u_tragu' => str_contains($trag, TAJNA),
            'kljuc_u_tragu' => str_contains($trag, KLJUC),
            'tajna_u_tekstu' => str_contains($tekst, TAJNA),
            'kljuc_u_tekstu' => str_contains($tekst, KLJUC),
        ];
    }
}

echo json_encode($rezultat, JSON_THROW_ON_ERROR);
