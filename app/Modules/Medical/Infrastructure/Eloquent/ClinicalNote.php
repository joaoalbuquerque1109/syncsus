<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ClinicalNote extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    public function resolveAuthor(): ?User
    {
        return User::query()->find($this->author_id);
    }

    public function getAuthorAttribute(): ?User
    {
        return $this->relationLoaded('author') ? $this->getRelation('author') : $this->resolveAuthor();
    }

    public function resolveSpecialty(): ?Specialty
    {
        return $this->resolveCoreReference(Specialty::class, 'specialty_public_id', 'specialty_id');
    }

    public function getSpecialtyAttribute(): ?Specialty
    {
        return $this->relationLoaded('specialty') ? $this->getRelation('specialty') : $this->resolveSpecialty();
    }

    /** @return BelongsTo<ClinicalNote, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_note_id');
    }

    protected function casts(): array
    {
        return [
            'clinical_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }
}
