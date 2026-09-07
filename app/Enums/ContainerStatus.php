<?php

namespace App\Enums;

enum ContainerStatus: string
{
    case Fcl = 'fcl';
    case Lcl = 'lcl';
    case Conventional = 'conventional';
}
