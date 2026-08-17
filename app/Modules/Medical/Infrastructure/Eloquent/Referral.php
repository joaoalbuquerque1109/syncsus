<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Referral extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolveRequestedBy(): ?User
    {
        return User::query()->find($this->requested_by);
    }

    public function resolveSpecialty(): ?Specialty
    {
        return $this->resolveCoreReference(Specialty::class, 'specialty_public_id', 'specialty_id');
    }

    public function getSpecialtyAttribute(): ?Specialty
    {
        return $this->relationLoaded('specialty') ? $this->getRelation('specialty') : $this->resolveSpecialty();
    }

    /** @return BelongsTo<ClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class);
    }

    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime'];
    }
}
