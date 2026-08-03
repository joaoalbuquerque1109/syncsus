<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryIntegration extends Model
{
    use HasPublicId;

    protected $guarded = [];

    protected $attributes = [
        'provider' => 'synclab',
        'is_active' => false,
        'transmission_enabled' => false,
        'result_sync_enabled' => false,
        'connection_status' => 'not_tested',
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

    /** @return HasMany<LaboratoryMaterial, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(LaboratoryMaterial::class);
    }

    /** @return HasMany<LaboratoryExam, $this> */
    public function exams(): HasMany
    {
        return $this->hasMany(LaboratoryExam::class);
    }

    /** @return HasMany<LaboratoryOrderTransmission, $this> */
    public function transmissions(): HasMany
    {
        return $this->hasMany(LaboratoryOrderTransmission::class);
    }

    protected function casts(): array
    {
        return [
            'username' => 'encrypted',
            'password' => 'encrypted',
            'settings' => 'array',
            'is_active' => 'boolean',
            'transmission_enabled' => 'boolean',
            'result_sync_enabled' => 'boolean',
            'last_connection_test_at' => 'immutable_datetime',
            'last_catalog_sync_at' => 'immutable_datetime',
        ];
    }
}
