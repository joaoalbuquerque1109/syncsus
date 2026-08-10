<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Actions;

use App\Modules\Administration\Application\Services\OrganizationCatalogBootstrapper;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final readonly class ProvisionTenantAction
{
    public function __construct(
        private OrganizationCatalogBootstrapper $catalogs,
        private TenantContext $tenantContext,
        private TenantConnectionManager $connectionManager,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{organization: Organization, health_unit: HealthUnit, manager: User}
     */
    public function execute(array $data): array
    {
        $previous = $this->tenantContext->isResolved()
            ? [$this->tenantContext->healthUnit(), $this->tenantContext->connectionName()]
            : null;
        try {
            return DB::connection('core')->transaction(function () use ($data): array {
                $cnes = preg_replace('/\D/', '', (string) $data['cnes_code']);
                $organization = Organization::query()->create([
                    'code' => $cnes,
                    'cnes_code' => $cnes,
                    'legal_name' => $data['legal_name'],
                    'trade_name' => $data['trade_name'],
                    'document_number' => filled($data['document_number'] ?? null)
                        ? preg_replace('/\D/', '', (string) $data['document_number'])
                        : null,
                    'timezone' => config('app.timezone'),
                    'locale' => config('app.locale'),
                    'is_active' => true,
                ]);
                $unit = HealthUnit::query()->create([
                    'organization_id' => $organization->getKey(),
                    'code' => $cnes,
                    'cnes_code' => $cnes,
                    'name' => $data['trade_name'],
                    'postal_code' => $data['postal_code'] ?? null,
                    'state' => mb_strtoupper((string) ($data['state'] ?? '')) ?: null,
                    'city' => $data['city'] ?? null,
                    'district' => $data['district'] ?? null,
                    'street' => $data['street'] ?? null,
                    'street_number' => $data['street_number'] ?? null,
                    'address_complement' => $data['address_complement'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'is_active' => true,
                ]);
                $this->tenantContext->reset();
                $this->tenantContext->resolve($unit, $this->connectionManager->connectionName($unit));
                $this->catalogs->bootstrap($organization, $unit);

                $manager = User::query()->create([
                    'organization_id' => $organization->getKey(),
                    'name' => $data['manager_name'],
                    'email' => mb_strtolower((string) $data['manager_email']),
                    'password' => $data['manager_password'],
                    'default_health_unit_id' => $unit->getKey(),
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
                $manager->healthUnits()->attach($unit->getKey());
                $manager->syncRoles(['manager']);

                return ['organization' => $organization, 'health_unit' => $unit, 'manager' => $manager];
            });
        } finally {
            $this->tenantContext->reset();
            if ($previous !== null) {
                $this->tenantContext->resolve($previous[0], $previous[1]);
            }
        }
    }
}
