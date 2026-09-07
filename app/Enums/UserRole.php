<?php

namespace App\Enums;

enum UserRole: string
{
    case Agent = 'agent';
    case Officer = 'officer';
    case Admin = 'admin';
}
