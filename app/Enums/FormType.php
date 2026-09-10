<?php

namespace App\Enums;

enum FormType: string
{
    case K1 = 'k1';
    case K2 = 'k2';
    case K3 = 'k3';
    case K8 = 'k8';
    case AttachmentA = 'attachment_a';
    case UCustoms = 'ucustoms';
    case AtaCarnet = 'ata_carnet';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::K1 => 'K1',
            self::K2 => 'K2',
            self::K3 => 'K3',
            self::K8 => 'K8',
            self::AttachmentA => 'LAMPIRAN A (TARIK BALIK)',
            self::UCustoms => 'uCUSTOMS',
            self::AtaCarnet => 'ATA CARNET',
            self::Other => 'LAIN-LAIN',
        };
    }
}
