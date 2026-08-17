<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Prescription extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function resolveProfessional(): ?User
    {
        return User::query()->find($this->professional_id);
    }

    public function getProfessionalAttribute(): ?User
    {
        return $this->relationLoaded('professional') ? $this->getRelation('professional') : $this->resolveProfessional();
    }

    /** @return HasMany<PrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('display_order');
    }

    /** @return BelongsTo<ClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class);
    }

    /** @return BelongsTo<Prescription, $this> */
    public function parentPrescription(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_prescription_id');
    }

    /** @return HasMany<Prescription, $this> */
    public function replacements(): HasMany
    {
        return $this->hasMany(self::class, 'parent_prescription_id');
    }

    protected function casts(): array
    {
        return [
            'finalized_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'replaced_at' => 'immutable_datetime',
        ];
    }
}
