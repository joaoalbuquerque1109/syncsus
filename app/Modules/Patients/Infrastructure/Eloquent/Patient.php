<?php

declare(strict_types=1);

namespace App\Modules\Patients\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Support\Models\CoreModel;
use App\Support\Models\HasPublicId;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

final class Patient extends CoreModel
{
    use HasPublicId;

    protected $guarded = [];

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<PatientIdentifier, $this> */
    public function identifiers(): HasMany
    {
        return $this->hasMany(PatientIdentifier::class)->orderByDesc('is_primary')->orderBy('type');
    }

    /** @return HasMany<PatientContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(PatientContact::class);
    }

    /** @return HasMany<PatientAddress, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(PatientAddress::class);
    }

    /** @return HasMany<PatientGuardian, $this> */
    public function guardians(): HasMany
    {
        return $this->hasMany(PatientGuardian::class);
    }

    /** @return HasMany<PatientAllergy, $this> */
    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    /** @return HasMany<PatientCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(PatientCondition::class);
    }

    /** @return HasMany<PatientMedication, $this> */
    public function medications(): HasMany
    {
        return $this->hasMany(PatientMedication::class);
    }

    /** @return HasOne<PatientSocialHistory, $this> */
    public function socialHistory(): HasOne
    {
        return $this->hasOne(PatientSocialHistory::class);
    }

    /** @return HasMany<Encounter, $this> */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class)->where(
            'health_unit_public_id',
            app(TenantContext::class)->healthUnit()->public_id,
        );
    }

    public function displayName(): string
    {
        return $this->social_name ?: $this->full_name;
    }

    public function birthDateLabel(): ?string
    {
        $birthDate = $this->getAttribute('birth_date');

        return $birthDate instanceof DateTimeInterface ? $birthDate->format('d/m/Y') : null;
    }

    public function ageYears(): ?int
    {
        $birthDate = $this->getAttribute('birth_date');

        return $birthDate instanceof DateTimeInterface ? (int) Carbon::instance($birthDate)->age : null;
    }

    public function sexEnum(): PatientSex
    {
        $sex = $this->getAttribute('sex');

        return $sex instanceof PatientSex ? $sex : PatientSex::from((string) $sex);
    }

    protected function casts(): array
    {
        return [
            'birth_date' => 'immutable_date',
            'sex' => PatientSex::class,
            'status' => PatientStatus::class,
            'is_disabled' => 'boolean',
            'mother_unknown' => 'boolean',
            'father_unknown' => 'boolean',
            'is_provisional' => 'boolean',
        ];
    }
}
