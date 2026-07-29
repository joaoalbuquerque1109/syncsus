<?php

declare(strict_types=1);

namespace App\Modules\Triage\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class TriageAssessment extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<QueueEntry, $this> */
    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(QueueEntry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(User::class, 'professional_id');
    }

    /** @return BelongsTo<ServicePoint, $this> */
    public function servicePoint(): BelongsTo
    {
        return $this->belongsTo(ServicePoint::class);
    }

    /** @return BelongsTo<TriageProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(TriageProtocol::class, 'triage_protocol_id');
    }

    /** @return BelongsTo<TriageFlowchart, $this> */
    public function flowchart(): BelongsTo
    {
        return $this->belongsTo(TriageFlowchart::class, 'triage_flowchart_id');
    }

    /** @return BelongsTo<TriageDiscriminator, $this> */
    public function discriminator(): BelongsTo
    {
        return $this->belongsTo(TriageDiscriminator::class, 'triage_discriminator_id');
    }

    /** @return BelongsTo<RiskLevel, $this> */
    public function riskLevel(): BelongsTo
    {
        return $this->belongsTo(RiskLevel::class);
    }

    /** @return BelongsTo<Queue, $this> */
    public function destinationQueue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'destination_queue_id');
    }

    /** @return HasMany<VitalSignMeasurement, $this> */
    public function vitalSigns(): HasMany
    {
        return $this->hasMany(VitalSignMeasurement::class);
    }

    /** @return HasMany<TriageAddendum, $this> */
    public function addenda(): HasMany
    {
        return $this->hasMany(TriageAddendum::class);
    }

    public function statusEnum(): TriageAssessmentStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof TriageAssessmentStatus ? $status : TriageAssessmentStatus::from((string) $status);
    }

    public function version(): int
    {
        return (int) $this->getAttribute('lock_version');
    }

    protected function casts(): array
    {
        return [
            'status' => TriageAssessmentStatus::class,
            'has_reported_allergies' => 'boolean',
            'uses_medications' => 'boolean',
            'requires_isolation' => 'boolean',
            'violence_signs' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finalized_at' => 'immutable_datetime',
        ];
    }
}
