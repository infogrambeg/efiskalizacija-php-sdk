<?php

declare(strict_types=1);

namespace Efiskalizacija\Enum;

enum TaxCategory: string
{
    case Oslobodjen = 'oslobodjen';
    case NijeUPdv = 'nije_u_pdv';
}
