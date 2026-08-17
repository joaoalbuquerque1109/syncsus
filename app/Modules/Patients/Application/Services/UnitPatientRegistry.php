<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientUnitParticipation;
use App\Modules\Patients\Infrastructure\Eloquent\UnitPatient;
use App\Support\Tenancy\TenantContext;

final readonly class UnitPatientRegistry
{
    public function __construct(private TenantContext $tenantContext) {}

    public function ensure(Patient $patient, ?HealthUnit $unit = null): UnitPatient
    {
        $unit ??= $this->tenantContext->healthUnit();
        $now = now();
        $record = UnitPatient::query()->firstOrCreate(
            [
                'patient_public_id' => (string) $patient->public_id,
                'health_unit_public_id' => (string) $unit->public_id,
            ],
            [
                'status' => 'active',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ],
        );

        PatientUnitParticipation::query()->updateOrCreate(
            [
                'patient_public_id' => (string) $patient->public_id,
                'health_unit_public_id' => (string) $unit->public_id,
            ],
            [
                'tenant_connection' => $this->tenantContext->connectionName(),
                'first_seen_at' => $record->first_seen_at ?? $now,
                'last_seen_at' => $now,
            ],
        );

        return $record;
    }
}
