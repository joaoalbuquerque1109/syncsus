<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class HealthUnit extends Model
{
    use HasUlids;

    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** @return BelongsToMany<HealthProfessional, $this> */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(HealthProfessional::class, 'health_professional_health_unit')
            ->withTimestamps();
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
