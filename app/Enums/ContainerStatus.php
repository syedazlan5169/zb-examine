<?php

namespace App\Enums;

enum ContainerStatus: string
{
    case Fcl = 'fcl';
    case Lcl = 'lcl';
    case Conventional = 'conventional';

    public function label(): string
    {
        return match ($this) {
            self::Fcl => 'FCL',
            self::Lcl => 'LCL',
            self::Conventional => 'CONVENTIONAL',
        };
    }
}
