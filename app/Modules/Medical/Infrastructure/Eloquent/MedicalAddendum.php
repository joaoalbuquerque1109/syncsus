<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class MedicalAddendum extends TenantModel
{
    use HasPublicId;

    protected $table = 'medical_addenda';

    protected $guarded = [];

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    public function resolveAuthor(): ?User
    {
        return User::query()->find($this->author_id);
    }

    public function getAuthorAttribute(): ?User
    {
        return $this->relationLoaded('author') ? $this->getRelation('author') : $this->resolveAuthor();
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
