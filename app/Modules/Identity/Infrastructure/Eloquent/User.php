<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Professionals\Infrastructure\Eloquent\MedicalShiftAttendance;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LogicException;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name',
    'email',
    'password',
    'organization_id',
    'platform_admin_slot',
    'default_health_unit_id',
    'is_active',
    'must_change_password',
    'last_login_at',
    'password_changed_at',
])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;
    use HasUlids;
    use Notifiable;

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    protected static function booted(): void
    {
        self::saving(function (User $user): void {
            if ((int) $user->platform_admin_slot === 1) {
                if ($user->organization_id !== null || $user->default_health_unit_id !== null) {
                    throw new LogicException('O administrador global não pode pertencer a uma organização.');
                }

                return;
            }
            if ($user->organization_id === null) {
                throw new LogicException('Usuários comuns devem pertencer obrigatoriamente a uma organização.');
            }
        });
    }

    /** @return BelongsTo<HealthUnit, $this> */
    public function defaultHealthUnit(): BelongsTo
    {
        return $this->belongsTo(HealthUnit::class, 'default_health_unit_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isPlatformAdministrator(): bool
    {
        return (int) $this->platform_admin_slot === 1;
    }

    /** @return BelongsToMany<HealthUnit, $this> */
    public function healthUnits(): BelongsToMany
    {
        return $this->belongsToMany(HealthUnit::class)->withTimestamps();
    }

    /** @return HasOne<HealthProfessional, $this> */
    public function professionalProfile(): HasOne
    {
        return $this->hasOne(HealthProfessional::class);
    }

    /** @return HasMany<MedicalShiftAttendance, $this> */
    public function medicalShiftAttendances(): HasMany
    {
        return $this->hasMany(MedicalShiftAttendance::class);
    }

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'password_changed_at' => 'immutable_datetime',
        ];
    }
}
