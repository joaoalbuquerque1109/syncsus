<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Application\Services\NumberSequenceService;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Support\Facades\DB;

final readonly class CreateProvisionalPatientAction
{
    public function __construct(private NumberSequenceService $sequences) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $user, int $healthUnitId): Patient
    {
        return DB::transaction(function () use ($data, $user, $healthUnitId): Patient {
            $organizationId = (int) HealthUnit::query()->whereKey($healthUnitId)->value('organization_id');
            abort_unless(
                $organizationId > 0
                    && ($user->isPlatformAdministrator() || (int) $user->organization_id === $organizationId),
                403,
            );
            $number = $this->sequences->next('patient_mrn');
            $name = trim((string) ($data['full_name'] ?? 'Paciente não identificado'));

            return Patient::query()->create([
                'organization_id' => $organizationId,
                'medical_record_number' => sprintf('P%08d', $number),
                'full_name' => $name,
                'normalized_name' => NormalizesBrazilianData::name($name),
                'estimated_age' => $data['estimated_age'] ?? null,
                'estimated_age_range' => $data['estimated_age_range'] ?? null,
                'sex' => $data['sex'] ?? PatientSex::Unknown,
                'reference_health_unit_id' => $healthUnitId,
                'is_provisional' => true,
                'provisional_description' => $data['provisional_description'],
                'status' => PatientStatus::Active,
                'created_by' => $user->getKey(),
                'updated_by' => $user->getKey(),
            ]);
        });
    }
}
