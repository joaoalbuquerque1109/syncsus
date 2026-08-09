<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Queues\Domain\Enums\PanelIdentificationMode;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use App\Support\Tenancy\PublicLookupService;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Panel extends TenantModel
{
    use HasPublicId;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::created(static function (Panel $panel): void {
            app(PublicLookupService::class)->registerPanel($panel);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_code';
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
    }

    public function getHealthUnitAttribute(): ?HealthUnit
    {
        return $this->relationLoaded('healthUnit') ? $this->getRelation('healthUnit') : $this->resolveHealthUnit();
    }

    /** @return BelongsToMany<Queue, $this> */
    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(Queue::class)->withTimestamps();
    }

    public function identificationMode(): PanelIdentificationMode
    {
        $mode = $this->getAttribute('identification_mode');

        return $mode instanceof PanelIdentificationMode ? $mode : PanelIdentificationMode::from((string) $mode);
    }

    public function previousCallsCount(): int
    {
        return max(1, min(20, (int) $this->getAttribute('previous_calls_count')));
    }

    protected function casts(): array
    {
        return [
            'identification_mode' => PanelIdentificationMode::class,
            'sound_enabled' => 'boolean',
            'is_active' => 'boolean',
            'last_heartbeat_at' => 'immutable_datetime',
        ];
    }
}
