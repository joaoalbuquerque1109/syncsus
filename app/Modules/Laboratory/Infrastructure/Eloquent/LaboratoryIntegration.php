<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Infrastructure\Eloquent;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Support\Models\HasPublicId;
use App\Support\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LaboratoryIntegration extends TenantModel
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

    protected $hidden = [
        'username',
        'password',
        'result_api_token_hash',
    ];

    public function resolveOrganization(): ?Organization
    {
        return $this->resolveCoreReference(Organization::class, 'organization_public_id', 'organization_id');
    }

    public function resolveHealthUnit(): ?HealthUnit
    {
        return $this->resolveCoreReference(HealthUnit::class, 'health_unit_public_id', 'health_unit_id');
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

    /** @return HasMany<ExamMapping, $this> */
    public function examMappings(): HasMany
    {
        return $this->hasMany(ExamMapping::class);
    }

    /** @return HasMany<ExamCatalogImportCandidate, $this> */
    public function examCatalogImportCandidates(): HasMany
    {
        return $this->hasMany(ExamCatalogImportCandidate::class);
    }

    /** @return HasMany<ExamGroupImportConflict, $this> */
    public function examGroupImportConflicts(): HasMany
    {
        return $this->hasMany(ExamGroupImportConflict::class);
    }

    /** @return HasMany<LaboratoryOrderTransmission, $this> */
    public function transmissions(): HasMany
    {
        return $this->hasMany(LaboratoryOrderTransmission::class);
    }

    /** @return HasMany<LaboratoryResultIngestion, $this> */
    public function resultIngestions(): HasMany
    {
        return $this->hasMany(LaboratoryResultIngestion::class);
    }

    public function hasCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
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
            'result_api_token_rotated_at' => 'immutable_datetime',
            'last_connection_test_at' => 'immutable_datetime',
            'last_catalog_sync_at' => 'immutable_datetime',
        ];
    }
}
