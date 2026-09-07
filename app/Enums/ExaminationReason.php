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
}
