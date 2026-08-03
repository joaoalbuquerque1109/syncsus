<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class ServicePoint extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return BelongsToMany<Queue, $this> */
    public function queues(): BelongsToMany
    {
        return $this->belongsToMany(Queue::class)->withTimestamps();
    }

    /** @return BelongsToMany<HealthProfessional, $this> */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(HealthProfessional::class, 'health_professional_service_point')
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
