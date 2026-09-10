<?php

namespace App\Enums;

enum AttendingOfficerType: string
{
    case Customs = 'customs';
    case Swcorps = 'swcorps';

    public function label(): string
    {
        return match ($this) {
            self::Customs => 'KASTAM',
            self::Swcorps => 'SWCORPS',
        };
    }
}
