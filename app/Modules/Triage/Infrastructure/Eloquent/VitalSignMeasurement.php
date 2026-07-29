<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VitalSignMeasurement extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<TriageAssessment, $this> */
    public function assessment(): BelongsTo
    {
        return $this->belongsTo(TriageAssessment::class, 'triage_assessment_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    protected function casts(): array
    {
        return [
            'measured_at' => 'immutable_datetime',
            'clinical_alerts' => 'array',
            'technical_alerts' => 'array',
            'corrected_by_addendum' => 'boolean',
        ];
    }
}
