<?php

namespace App\Enums;

enum ExaminationReason: string
{
    case AssessingOfficerInstruction = 'assessing_officer_instruction';
    case Drawback = 'drawback';
    case ExportCancelled = 'export_cancelled';
    case TransferK8 = 'transfer_k8';
    case Disposal = 'disposal';
    case AtaCarnet = 'ata_carnet';
    case TemporaryImport = 'temporary_import';
    case TemporaryExport = 'temporary_export';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AssessingOfficerInstruction => 'ARAHAN PEGAWAI PENAKSIR',
            self::Drawback => 'DRAWBACK',
            self::ExportCancelled => 'EKSPORT DIBATALKAN',
            self::TransferK8 => 'PEMINDAHAN (K8)',
            self::Disposal => 'PELUPUSAN',
            self::AtaCarnet => 'ATA CARNET',
            self::TemporaryImport => 'IMPORT SEMENTARA',
            self::TemporaryExport => 'EKSPORT SEMENTARA',
            self::Other => 'LAIN-LAIN',
        };
    }
}
