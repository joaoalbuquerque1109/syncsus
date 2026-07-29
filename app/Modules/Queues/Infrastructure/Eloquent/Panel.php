<?php

declare(strict_types=1);

namespace App\Modules\Queues\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Queues\Domain\Enums\PanelIdentificationMode;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Panel extends Model
{
    use HasPublicId;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'public_code';
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function healthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class);
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
