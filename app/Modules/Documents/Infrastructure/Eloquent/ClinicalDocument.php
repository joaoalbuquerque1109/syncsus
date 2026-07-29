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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ClinicalDocument extends Model
{
    use HasPublicId;

    protected $table = 'documents';

    protected $guarded = [];

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
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
