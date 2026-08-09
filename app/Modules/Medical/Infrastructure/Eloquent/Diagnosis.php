<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Diagnosis extends TenantModel
{
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

    public function resolveCatalogCode(): ?DiagnosisCode
    {
        return DiagnosisCode::query()->find($this->diagnosis_code_id);
    }

    public function getCatalogCodeAttribute(): ?DiagnosisCode
    {
        return $this->relationLoaded('catalogCode') ? $this->getRelation('catalogCode') : $this->resolveCatalogCode();
    }

    public function resolveDiagnosedBy(): ?User
    {
        return User::query()->find($this->diagnosed_by);
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'diagnosed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
