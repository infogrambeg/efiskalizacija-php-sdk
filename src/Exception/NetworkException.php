<?php

declare(strict_types=1);

namespace Efiskalizacija\Exception;

/**
 * Timeout, DNS, konekcioni problemi.
 *
 * Nosi saznanje o tome da li je zahtev poslat ({@see self::wasRequestSent()}),
 * jer od toga zavisi da li ponovni pokušaj sme da se pošalje: zahtev koji je
 * stigao do servera možda je već obrađen (npr. izdat fiskalni račun).
 */
class NetworkException extends EfiskalizacijaException
{
    // Podrazumevana vrednost: i objekat bez ovog svojstva (npr. `unserialize()`
    // izuzetka iz 1.1.1) daje null, ne grešku neinicijalizovanog svojstva.
    private ?bool $requestSent = null;

    /**
     * @param ?bool $requestSent false = sigurno je da nijedan bajt zahteva nije
     *                           poslat; true = zahtev je (bar delimično) poslat;
     *                           null = nepoznato (podrazumevano, kao do 1.1.1)
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        ?string $responseBody = null,
        ?bool $requestSent = null,
    ) {
        parent::__construct($message, $code, $previous, $responseBody);
        $this->requestSent = $requestSent;
    }

    /**
     * Da li je zahtev poslat pre greške: false samo kad je sigurno da nije
     * (server ga nije mogao obraditi), true kad jeste, null kad se ne zna.
     */
    public function wasRequestSent(): ?bool
    {
        return $this->requestSent;
    }
}
