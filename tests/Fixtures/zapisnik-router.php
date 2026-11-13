<?php

declare(strict_types=1);

/**
 * Router za `php -S` u testu {@see \Efiskalizacija\Tests\Unit\CurlPotpisaniCiljTest}:
 * beleži sirov `REQUEST_URI`, metod, zaglavlja i telo svakog zahteva (base64,
 * bajt-verno) u fajl iz `EFISK_ZAPISNIK`, jedan JSON red po zahtevu, pa
 * odgovara uspehom.
 */

$zapisnik = (string) getenv('EFISK_ZAPISNIK');
$zapis = [
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'uri' => base64_encode((string) ($_SERVER['REQUEST_URI'] ?? '')),
    'headers' => array_map('base64_encode', getallheaders()),
    'body' => base64_encode((string) file_get_contents('php://input')),
];
file_put_contents($zapisnik, json_encode($zapis, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);

header('Content-Type: application/json');
echo '{"success":true,"data":{}}';
