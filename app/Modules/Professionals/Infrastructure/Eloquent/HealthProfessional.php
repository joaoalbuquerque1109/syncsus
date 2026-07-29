<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class HealthProfessional extends Model
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ProfessionalRegistration, $this> */
    public function registrations(): HasMany
    {
        return $this->hasMany(ProfessionalRegistration::class)->orderByDesc('is_primary');
    }

    /** @return BelongsToMany<Specialty, $this> */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'health_professional_specialty')
            ->withPivot(['rqe_number', 'registered_at', 'is_primary'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<HealthUnit, $this> */
    public function healthUnits(): BelongsToMany
    {
        return $this->belongsToMany(HealthUnit::class, 'health_professional_health_unit')
            ->withTimestamps();
    }

    public function displayName(): string
    {
        return trim(collect([$this->treatment_name, $this->social_name ?: $this->full_name])->filter()->join(' '));
    }

    public function primaryRegistrationLabel(): ?string
    {
        $registration = $this->registrations->firstWhere('is_primary', true)
            ?? $this->registrations->first();

        return $registration?->label();
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }
}
