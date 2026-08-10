<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent\Concerns;

use App\Modules\Patients\Application\Services\UnitPatientRegistry;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\UnitPatient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToUnitPatient
{
    protected static function bootBelongsToUnitPatient(): void
    {
        static::addGlobalScope('active_unit_patient', function (Builder $query): void {
            $context = app(TenantContext::class);
            $query->where(
                $query->qualifyColumn('health_unit_public_id'),
                $context->healthUnit()->public_id,
            );
        });

        static::creating(function (self $record): void {
            $patient = Patient::query()->find($record->patient_id);
            if (! $patient instanceof Patient) {
                throw new LogicException('Registro local exige paciente Core válido.');
            }

            $unitPatient = app(UnitPatientRegistry::class)->ensure($patient);
            $record->forceFill([
                'unit_patient_id' => $unitPatient->getKey(),
                'patient_public_id' => $unitPatient->patient_public_id,
                'health_unit_public_id' => $unitPatient->health_unit_public_id,
            ]);
        });
    }

    /** @return BelongsTo<UnitPatient, $this> */
    public function unitPatient(): BelongsTo
    {
        return $this->belongsTo(UnitPatient::class);
    }
}
