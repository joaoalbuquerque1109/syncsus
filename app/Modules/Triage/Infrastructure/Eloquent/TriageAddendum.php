<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TriageAddendum extends Model
{
    use HasPublicId;

    protected $table = 'triage_addenda';

    protected $guarded = [];

    /** @return BelongsTo<TriageAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(TriageAssessment::class, 'triage_assessment_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    protected function casts(): array
    {
        return ['recorded_at' => 'immutable_datetime'];
    }
}
