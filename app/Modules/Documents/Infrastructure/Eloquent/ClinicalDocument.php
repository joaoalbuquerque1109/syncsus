<?php

declare(strict_types=1);

namespace App\Modules\Documents\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Documents\Domain\Enums\ClinicalDocumentType;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ClinicalDocument extends TenantModel
{
    use HasPublicId;

    protected $table = 'documents';

    protected $guarded = [];

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function getHealthUnitAttribute(): ?HealthUnit
    {
        return $this->relationLoaded('healthUnit') ? $this->getRelation('healthUnit') : $this->resolveHealthUnit();
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

    public function getPatientAttribute(): ?Patient
    {
        return $this->relationLoaded('patient') ? $this->getRelation('patient') : $this->resolvePatient();
    }

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    public function resolveCreator(): ?User
    {
        return User::query()->find($this->created_by);
    }

    public function getCreatorAttribute(): ?User
    {
        return $this->relationLoaded('creator') ? $this->getRelation('creator') : $this->resolveCreator();
    }

    public function resolveVoidedBy(): ?User
    {
        $voidedBy = $this->getRawOriginal('voided_by');

        return $voidedBy === null ? null : User::query()->find($voidedBy);
    }

    /** @return HasMany<DocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderByDesc('version_number');
    }

    /** @return BelongsTo<DocumentVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    public function typeEnum(): ClinicalDocumentType
    {
        $type = $this->getAttribute('document_type');

        return $type instanceof ClinicalDocumentType ? $type : ClinicalDocumentType::from((string) $type);
    }

    protected function casts(): array
    {
        return [
            'document_type' => ClinicalDocumentType::class,
            'voided_at' => 'immutable_datetime',
        ];
    }
}
