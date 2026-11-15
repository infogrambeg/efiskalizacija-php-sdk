<?php

declare(strict_types=1);

/**
 * Pod-proces za {@see \Efiskalizacija\Tests\Unit\PodklasaAbstractApiTest}:
 * podklasa `AbstractApi` koja već ima sopstveni `pause()` ili `currentTime()`
 * (oblik iz argumenta) izvršava tri poziva:
 *
 * 1. GET `/status`: 503 pa 200 — jedan ponovni pokušaj (sat, pauza,
 *    `isSafeToRepeat`);
 * 2. POST `/nesto` bez broja računa: 503 — zahtev nije bezbedan za
 *    ponavljanje, pa prolazi kroz `warnUnknownOutcome` (grana statusa) i
 *    baca izuzetak bez ponavljanja;
 * 3. POST `/mrezno` bez broja računa: klijent baca `NetworkException` sa
 *    `wasRequestSent() === null` — prolazi kroz `warnUnknownOutcome`
 *    (mrežna grana) i baca izuzetak bez ponavljanja.
 *
 * Fatal pri nasleđivanju ne može da se uhvati u procesu testova, pa se
 * podklasa deklariše ovde. Izlaz (JSON): koje metode podklase je SDK
 * pozvao, `X-Timestamp` svakog zahteva GET-a, broj zahteva i klasa
 * izuzetka za svaki POST i poruke WARNING zapisa.
 *
 * Argumenti: oblik, direktorijum `Psr\Log` interfejsa.
 */

[, $oblik, $psrLog] = $argv;
$src = dirname(__DIR__, 2) . '/src/';

spl_autoload_register(static function (string $klasa) use ($src, $psrLog): void {
    foreach (['Efiskalizacija\\' => $src, 'Psr\\Log\\' => rtrim($psrLog, '/') . '/'] as $prefiks => $dir) {
        if (strncmp($klasa, $prefiks, strlen($prefiks)) === 0) {
            $fajl = $dir . str_replace('\\', '/', substr($klasa, strlen($prefiks))) . '.php';
            if (is_file($fajl)) {
                require $fajl;
            }

            return;
        }
    }
});

use Efiskalizacija\Api\AbstractApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\Exception\EfiskalizacijaException;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;
use Psr\Log\AbstractLogger;

$GLOBALS['pozvano'] = [];

$http = new class () implements HttpClientInterface {
    /** @var list<string> */
    public array $vremena = [];

    public int $postova = 0;

    public int $mreznih = 0;

    public function request(string $method, string $url, array $headers = [], string $body = ''): Response
    {
        if ($method === 'POST' && str_ends_with($url, '/mrezno')) {
            $this->mreznih++;

            // requestSent null: ne zna se da li je zahtev stigao do servera
            throw new NetworkException('Veza prekinuta posle slanja');
        }
        if ($method === 'POST') {
            $this->postova++;

            return new Response(503, '{}');
        }
        $this->vremena[] = $headers['X-Timestamp'];

        return count($this->vremena) === 1
            ? new Response(503, '{}')
            : new Response(200, '{"success":true,"data":{}}');
    }
};

$logger = new class () extends AbstractLogger {
    /** @var list<string> */
    public array $upozorenja = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if ($level === 'warning') {
            $this->upozorenja[] = (string) $message;
        }
    }
};

$config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 1, retryBaseDelayMs: 1);
$signer = new HmacSigner($config->getApiSecret());

/** Sva tri poziva i izlaz; izuzetak POST-a se beleži, ne prekida pod-proces. */
$izvrsi = static function (object $api) use ($http, $logger): void {
    $api->status();
    $izuzetakPosta = null;
    try {
        $api->posalji('/nesto');
    } catch (EfiskalizacijaException $e) {
        $izuzetakPosta = $e::class;
    }
    $izuzetakMreznog = null;
    try {
        $api->posalji('/mrezno');
    } catch (EfiskalizacijaException $e) {
        $izuzetakMreznog = $e::class;
    }

    echo json_encode([
        'pozvano' => $GLOBALS['pozvano'],
        'vremena' => $http->vremena,
        'postova' => $http->postova,
        'izuzetak_posta' => $izuzetakPosta,
        'mreznih' => $http->mreznih,
        'izuzetak_mreznog' => $izuzetakMreznog,
        'upozorenja' => $logger->upozorenja,
    ], JSON_THROW_ON_ERROR);
};

// Oblik „<ime> <vidljivost>": podklasa ima metodu sa imenom privatnog
// pomoćnika `AbstractApi` (1.1.0), drugog potpisa (parametar i povratni tip
// `\stdClass`), kao private, protected, public ili public static. Klasa se
// gradi iz zatvorenog skupa imena i vidljivosti, pa `eval` ne vidi slobodan
// ulaz.
$pomocnici = ['sleepFor', 'clockNow', 'nextTimestamp', 'queryString', 'isSafeToRepeat', 'warnUnknownOutcome'];
$vidljivosti = ['private' => 'private', 'protected' => 'protected', 'public' => 'public', 'static' => 'public static'];
$delovi = explode(' ', $oblik);
if (count($delovi) === 2 && in_array($delovi[0], $pomocnici, true) && isset($vidljivosti[$delovi[1]])) {
    [$ime, $vidljivost] = $delovi;
    $klasa = 'PodklasaSa' . ucfirst($ime) . ucfirst($vidljivost);
    eval(sprintf(
        'final class %s extends \Efiskalizacija\Api\AbstractApi {
            public function status(): array
            {
                return $this->get(\'/status\');
            }

            public function posalji(string $putanja): array
            {
                return $this->post($putanja, [\'x\' => 1]);
            }

            %s function %s(\stdClass $drugi): \stdClass
            {
                $GLOBALS[\'pozvano\'][] = \'%s\';

                return $drugi;
            }
        }',
        $klasa,
        $vidljivosti[$vidljivost],
        $ime,
        $ime,
    ));
    $izvrsi(new $klasa($config, $http, $signer, $logger));

    exit(0);
}

$api = match ($oblik) {
    'private pause()' => new class ($config, $http, $signer, $logger) extends AbstractApi {
        public function status(): array
        {
            return $this->get('/status');
        }

        public function posalji(string $putanja): array
        {
            return $this->post($putanja, ['x' => 1]);
        }

        private function pause(): void
        {
            $GLOBALS['pozvano'][] = 'pause';
        }
    },
    'currentTime(): float' => new class ($config, $http, $signer, $logger) extends AbstractApi {
        public function status(): array
        {
            return $this->get('/status');
        }

        public function posalji(string $putanja): array
        {
            return $this->post($putanja, ['x' => 1]);
        }

        protected function currentTime(): float
        {
            $GLOBALS['pozvano'][] = 'currentTime';

            return 1.5;
        }
    },
    'pause(int $sekundi)' => new class ($config, $http, $signer, $logger) extends AbstractApi {
        public function status(): array
        {
            return $this->get('/status');
        }

        public function posalji(string $putanja): array
        {
            return $this->post($putanja, ['x' => 1]);
        }

        protected function pause(int $sekundi): void
        {
            // Samo beleži: SDK bi ovde predao mikrosekunde kao sekunde
            $GLOBALS['pozvano'][] = 'pause:' . $sekundi;
        }
    },
};

$izvrsi($api);
