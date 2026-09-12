<?php

namespace App\Services;

use App\Data\ExaminationSubmissionData;
use App\Enums\UserRole;
use App\Models\User;

final class AgentProfileEnrichmentService
{
    public function enrich(User $user, ExaminationSubmissionData $data): void
    {
        if ($user->role !== UserRole::Agent) {
            return;
        }

        $lockedUser = User::query()
            ->whereKey($user->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $attributes = [
            'phone' => $data->agentPhone,
            'agent_code' => $data->agentCode,
            'company_name' => $data->agentCompanyName,
            'station_code' => $data->agentStationCode,
        ];
        $changed = false;

        foreach ($attributes as $field => $value) {
            if ($this->isBlank($lockedUser->{$field}) && ! $this->isBlank($value)) {
                $lockedUser->{$field} = $value;
                $changed = true;
            }
        }

        if ($changed) {
            $lockedUser->save();
        }
    }

    private function isBlank(mixed $value): bool
    {
        return trim((string) $value) === '';
    }
}
