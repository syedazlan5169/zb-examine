<?php

namespace App\Models;

use App\Enums\AttendingOfficerType;
use App\Enums\ContainerStatus;
use App\Enums\ExaminationLocation;
use App\Enums\ExaminationReason;
use App\Enums\FormType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Examination extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'submission_no',
        'user_id',

        'agent_name',
        'agent_phone',
        'agent_code',
        'agent_company_name',
        'agent_station_code',

        'location',
        'form_type',
        'form_type_other',
        'container_status',
        'reason',
        'reason_other',
        'attending_officer_type',

        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'location' => ExaminationLocation::class,
            'form_type' => FormType::class,
            'container_status' => ContainerStatus::class,
            'reason' => ExaminationReason::class,
            'attending_officer_type' => AttendingOfficerType::class,
            'submitted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customsFormNumbers(): HasMany
    {
        return $this->hasMany(ExaminationCustomsFormNumber::class)
            ->orderBy('display_order');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ExaminationPhoto::class)
            ->orderBy('display_order');
    }
}
