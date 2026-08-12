<?php

declare(strict_types=1);

namespace App\Modules\Administration\Infrastructure\Eloquent;

use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Specialty extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<HealthProfessional, $this> */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(HealthProfessional::class, 'health_professional_specialty')
            ->withPivot(['rqe_number', 'registered_at', 'is_primary'])
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
