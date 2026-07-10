<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

enum BusinessType: string
{
    case Floreria = 'floreria_regalos';
    case Ropa = 'ropa_boutique';
    case Accesorios = 'accesorios';
    case Peluches = 'peluches';
    case Minimarket = 'minimarket';
    case Otro = 'otro';
}
