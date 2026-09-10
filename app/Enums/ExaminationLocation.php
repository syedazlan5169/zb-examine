<?php

namespace App\Enums;

enum ExaminationLocation: string
{
    case ContainerGateTerminal = 'container_gate_terminal';
    case ConventionalGate = 'conventional_gate';

    public function label(): string
    {
        return match ($this) {
            self::ContainerGateTerminal => 'TERMINAL GATE KONTENA',
            self::ConventionalGate => 'GATE CONVENTIONAL',
        };
    }
}
