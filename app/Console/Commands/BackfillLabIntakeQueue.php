<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Administration\Application\Services\HealthUnitFlowBootstrapper;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class BackfillLabIntakeQueue extends Command
{
    protected $signature = 'sync-sus:backfill-lab-intake-queue';

    protected $description = 'Cria o setor/fila de recepção de exames em unidades já provisionadas e vincula ao tipo de entrada Retorno';

    public function handle(
        HealthUnitFlowBootstrapper $flowBootstrapper,
        TenantContext $tenantContext,
        TenantConnectionManager $connections,
    ): int {
        foreach (HealthUnit::query()->cursor() as $unit) {
            $tenantContext->reset();
            $tenantContext->resolve($unit, $connections->connectionName($unit));

            $flowBootstrapper->bootstrap($unit);

            $labIntakeQueueId = Queue::query()
                ->where('health_unit_id', $unit->getKey())
                ->where('code', 'QUEUE-LAB_INTAKE')
                ->value('id');

            if ($labIntakeQueueId === null) {
                continue;
            }

            $updated = EntryType::query()
                ->where('organization_id', $unit->organization_id)
                ->where('code', 'RETURN')
                ->whereNull('default_queue_id')
                ->update(['default_queue_id' => $labIntakeQueueId]);

            if ($updated > 0) {
                $this->components->info("Unidade {$unit->code}: fila de recepção de exames vinculada ao tipo de entrada Retorno.");
            }
        }

        $tenantContext->reset();

        return self::SUCCESS;
    }
}
