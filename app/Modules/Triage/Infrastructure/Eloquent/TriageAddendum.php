<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TriageAddendum extends TenantModel
{
    use HasPublicId;

    protected $table = 'triage_addenda';

    protected $guarded = [];

    /** @return BelongsTo<TriageAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(TriageAssessment::class, 'triage_assessment_id');
    }

    public function resolveAuthor(): ?User
    {
        return User::query()->find($this->author_id);
    }

    public function getAuthorAttribute(): ?User
    {
        return $this->relationLoaded('author')
            ? $this->getRelation('author')
            : $this->resolveAuthor();
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
