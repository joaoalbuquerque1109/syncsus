<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryOrderTransmission extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'status' => LaboratoryTransmissionStatus::AwaitingConfiguration->value,
        'attempt_count' => 0,
    ];

    public function resolveOrganization(): ?Organization
    {
        return $this->resolveCoreReference(Organization::class, 'organization_public_id', 'organization_id');
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
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

    /** @return HasMany<LaboratoryResultIngestion, $this> */
    public function resultIngestions(): HasMany
    {
        return $this->hasMany(LaboratoryResultIngestion::class);
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
            'sending_started_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'request_payload' => 'encrypted:array',
        ];
    }
}
