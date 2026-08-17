<?php

declare(strict_types=1);

namespace App\Modules\Documents\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MedicalCertificate extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function resolvePatient(): ?Patient
    {
        return $this->resolveCoreReference(Patient::class, 'patient_public_id', 'patient_id');
    }

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    public function resolveIssuer(): ?User
    {
        return User::query()->find($this->issued_by);
    }

    public function resolveDiagnosisCode(): ?DiagnosisCode
    {
        return $this->diagnosis_code_id === null ? null : DiagnosisCode::query()->find($this->diagnosis_code_id);
    }

    /** @return BelongsTo<ClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class);
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'include_cid' => 'boolean',
            'cid_authorized_at' => 'immutable_datetime',
            'issued_at' => 'immutable_datetime',
        ];
    }
}
