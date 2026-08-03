<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryOrderTransmission extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => LaboratoryTransmissionStatus::AwaitingConfiguration->value,
        'attempt_count' => 0,
    ];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
    }

    /** @return BelongsTo<LaboratoryIntegration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(LaboratoryIntegration::class, 'laboratory_integration_id');
    }

    /** @return BelongsTo<ExamOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ExamOrder::class, 'exam_order_id');
    }

    /** @return HasMany<LaboratoryTransmissionAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(LaboratoryTransmissionAttempt::class)->orderByDesc('attempt_number');
    }

    public function statusEnum(): LaboratoryTransmissionStatus
    {
        $status = $this->getAttribute('status');

        return $status instanceof LaboratoryTransmissionStatus
            ? $status
            : LaboratoryTransmissionStatus::from((string) $status);
    }

    protected function casts(): array
    {
        return [
            'status' => LaboratoryTransmissionStatus::class,
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }
}
