<?php

declare(strict_types=1);

namespace Efiskalizacija\Exception;

/** HTTP 401 - Neispravan API key ili HMAC potpis. */
class AuthenticationException extends EfiskalizacijaException
{
}
