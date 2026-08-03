<?php

declare(strict_types=1);

namespace App\Modules\Medical\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ExamOrder extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(function (ExamOrder $order): void {
            $attributes = $order->getAttributes();
            if (($attributes['organization_id'] ?? null) === null || ($attributes['health_unit_id'] ?? null) === null) {
                $encounter = Encounter::query()->with('healthUnit')->find($order->encounter_id);
                if ($encounter !== null) {
                    if (($attributes['organization_id'] ?? null) === null) {
                        $order->organization_id = $encounter->healthUnit->organization_id;
                    }
                    if (($attributes['health_unit_id'] ?? null) === null) {
                        $order->health_unit_id = $encounter->health_unit_id;
                    }
                }
            }

            if (($attributes['created_by'] ?? null) === null) {
                $order->created_by = $order->requested_by;
            }
            if (($attributes['origin'] ?? null) === null) {
                $order->origin = 'medical';
            }
            if (($attributes['status'] ?? null) === null) {
                $order->status = 'pending';
            }
        });
    }

    /** @return BelongsTo<MedicalConsultation, $this> */
    public function consultation(): BelongsTo
    {
        return $this->belongsTo(MedicalConsultation::class, 'medical_consultation_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

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

    /** @return HasMany<ExamOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(ExamOrderItem::class);
    }

    /** @return BelongsTo<ClinicalDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(ClinicalDocument::class);
    }

    /** @return HasMany<LaboratoryOrderTransmission, $this> */
    public function laboratoryTransmissions(): HasMany
    {
        return $this->hasMany(LaboratoryOrderTransmission::class);
    }

    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }
}
