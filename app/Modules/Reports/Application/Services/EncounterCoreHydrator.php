<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Database\Eloquent\Collection;

final class EncounterCoreHydrator
{
    /** @param Collection<int, Encounter> $encounters */
    public function hydrate(Collection $encounters): void
    {
        $patients = Patient::query()->whereKey($encounters->pluck('patient_id')->unique()->all())->get()
            ->keyBy(fn (Patient $patient): int => (int) $patient->getKey());
        $riskLevels = RiskLevel::query()
            ->whereKey($encounters->pluck('risk_level_id')->filter()->unique()->all())->get()
            ->keyBy(fn (RiskLevel $risk): int => (int) $risk->getKey());
        $specialties = Specialty::query()
            ->whereKey($encounters->pluck('assigned_specialty_id')->filter()->unique()->all())->get()
            ->keyBy(fn (Specialty $specialty): int => (int) $specialty->getKey());
        $users = User::query()
            ->whereKey($encounters->pluck('medicalConsultation.professional_id')->filter()->unique()->all())->get()
            ->keyBy(fn (User $user): string => (string) $user->getKey());

        foreach ($encounters as $encounter) {
            $encounter->setRelation('patient', $patients->get((int) $encounter->patient_id));
            $encounter->setRelation('riskLevel', $riskLevels->get((int) $encounter->risk_level_id));
            $encounter->setRelation(
                'assignedSpecialty',
                $specialties->get((int) $encounter->assigned_specialty_id),
            );
            if ($encounter->medicalConsultation !== null) {
                $encounter->medicalConsultation->setRelation(
                    'professional',
                    $users->get((string) $encounter->medicalConsultation->professional_id),
                );
            }
        }
    }
}
