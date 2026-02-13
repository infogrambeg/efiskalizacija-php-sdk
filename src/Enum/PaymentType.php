<?php

declare(strict_types=1);

namespace Efiskalizacija\Enum;

enum PaymentType: string
{
    case Gotovina = 'gotovina';
    case Kartica = 'kartica';
    case Virman = 'virman';
    case Vaucer = 'vaucer';
    case Instant = 'instant';
    case Drugo = 'drugo';
}
