<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExaminationPhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'examination_id',
        'storage_disk',
        'storage_path',
        'mime_type',
        'file_size',
        'width',
        'height',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class);
    }
}
