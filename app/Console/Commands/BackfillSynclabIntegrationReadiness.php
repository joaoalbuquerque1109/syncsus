<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class BackfillSynclabIntegrationReadiness extends Command
{
    protected $signature = 'sync-sus:backfill-synclab-readiness';

    protected $description = 'Habilita o envio de requisicoes Synclab automaticamente (sem credenciais) para unidades ja provisionadas';

    public function handle(TenantContext $tenantContext, TenantConnectionManager $connections): int
    {
        foreach (HealthUnit::query()->cursor() as $unit) {
            $tenantContext->reset();
            $tenantContext->resolve($unit, $connections->connectionName($unit));

            $integration = LaboratoryIntegration::query()->firstOrNew([
                'health_unit_id' => $unit->getKey(),
                'provider' => 'synclab',
            ]);

            // So toca em linhas ainda intocadas: nunca foi habilitada e ninguem
            // preencheu credenciais ou um CNES manualmente - evita sobrescrever
            // qualquer configuracao real que um administrador ja tenha feito.
            $untouched = ! $integration->exists
                || (
                    ! $integration->transmission_enabled
                    && blank($integration->username)
                    && blank($integration->password)
                    && blank($integration->external_tenant_code)
                );
            if (! $untouched) {
                continue;
            }

            $integration->fill([
                'organization_id' => $unit->organization_id,
                'base_url' => $integration->base_url ?: rtrim((string) config('sync_sus.synclab.base_url'), '/'),
                'external_tenant_code' => $unit->cnes_code,
                'is_active' => true,
                'transmission_enabled' => true,
                'connection_status' => 'configured',
            ])->save();

            $this->components->info("Unidade {$unit->code}: envio Synclab habilitado automaticamente (CNES {$unit->cnes_code}).");
        }

        $tenantContext->reset();

        return self::SUCCESS;
    }
}
