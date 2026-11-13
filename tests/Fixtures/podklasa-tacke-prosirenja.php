<?php

declare(strict_types=1);

/**
 * Pod-proces za {@see \Efiskalizacija\Tests\Unit\PodklasaAbstractApiTest}:
 * podklasa `AbstractApi` koja već ima sopstveni `pause()` ili `currentTime()`
 * (oblik iz argumenta) izvršava poziv sa jednim ponovnim pokušajem.
 *
 * Fatal pri nasleđivanju ne može da se uhvati u procesu testova, pa se
 * podklasa deklariše ovde. Izlaz (JSON): koje metode podklase je SDK
 * pozvao, broj zahteva i `X-Timestamp` svakog zahteva.
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
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;

$GLOBALS['pozvano'] = [];

$http = new class () implements HttpClientInterface {
    /** @var list<string> */
    public array $vremena = [];

    public function request(string $method, string $url, array $headers = [], string $body = ''): Response
    {
        $this->vremena[] = $headers['X-Timestamp'];

        return count($this->vremena) === 1
            ? new Response(503, '{}')
            : new Response(200, '{"success":true,"data":{}}');
    }
};

$config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 1, retryBaseDelayMs: 1);
$signer = new HmacSigner($config->getApiSecret());

// Oblik „<ime> <vidljivost>": podklasa ima metodu sa imenom privatnog
// pomoćnika `AbstractApi` (1.1.0), drugog potpisa (parametar i povratni tip
// `\stdClass`), kao private, protected, public ili public static. Klasa se
// gradi iz zatvorenog skupa imena i vidljivosti, pa `eval` ne vidi slobodan
// ulaz.
$pomocnici = ['sleepFor', 'clockNow', 'nextTimestamp', 'queryString'];
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
    $api = new $klasa($config, $http, $signer);
    $api->status();

    echo json_encode(['pozvano' => $GLOBALS['pozvano'], 'vremena' => $http->vremena], JSON_THROW_ON_ERROR);

    exit(0);
}

$api = match ($oblik) {
    'private pause()' => new class ($config, $http, $signer) extends AbstractApi {
        public function status(): array
        {
            return $this->get('/status');
        }

        private function pause(): void
        {
            $GLOBALS['pozvano'][] = 'pause';
        }
    },
    'currentTime(): float' => new class ($config, $http, $signer) extends AbstractApi {
        public function status(): array
        {
            return $this->get('/status');
        }

        protected function currentTime(): float
        {
            $GLOBALS['pozvano'][] = 'currentTime';

            return 1.5;
        }
    },
    'pause(int $sekundi)' => new class ($config, $http, $signer) extends AbstractApi {
        public function status(): array
        {
            return $this->get('/status');
        }

        protected function pause(int $sekundi): void
        {
            // Samo beleži: SDK bi ovde predao mikrosekunde kao sekunde
            $GLOBALS['pozvano'][] = 'pause:' . $sekundi;
        }
    },
};

$api->status();

echo json_encode(['pozvano' => $GLOBALS['pozvano'], 'vremena' => $http->vremena], JSON_THROW_ON_ERROR);
