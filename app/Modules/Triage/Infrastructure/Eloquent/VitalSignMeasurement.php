<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class VitalSignMeasurement extends TenantModel
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

    public function resolveRecordedBy(): ?User
    {
        return User::query()->find($this->recorded_by);
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
