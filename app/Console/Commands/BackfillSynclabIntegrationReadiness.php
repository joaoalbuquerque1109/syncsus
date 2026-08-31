<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Services\SynclabIntegrationReadiness;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class BackfillSynclabIntegrationReadiness extends Command
{
    protected $signature = 'sync-sus:backfill-synclab-readiness';

    protected $description = 'Habilita envio e recepcao Synclab automaticamente (sem credenciais) para unidades ja provisionadas';

    public function handle(
        TenantContext $tenantContext,
        TenantConnectionManager $connections,
        SynclabIntegrationReadiness $readiness,
    ): int {
        foreach (HealthUnit::query()->cursor() as $unit) {
            $tenantContext->reset();
            $tenantContext->resolve($unit, $connections->connectionName($unit));

            if ($readiness->ensureReady($unit)) {
                $this->components->info("Unidade {$unit->code}: integracao Synclab (envio e recepcao) habilitada automaticamente (CNES {$unit->cnes_code}).");
            }
        }

        $tenantContext->reset();

        return self::SUCCESS;
    }
}
