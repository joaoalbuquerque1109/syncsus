<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;

final readonly class OrganizationCatalogBootstrapper
{
    public function __construct(private HealthUnitFlowBootstrapper $flow) {}

    public function bootstrap(Organization $organization, HealthUnit $unit): void
    {
        foreach ([
            ['code' => 'CLINICA', 'name' => 'Clínica médica', 'display_order' => 10],
            ['code' => 'PEDIATRIA', 'name' => 'Pediatria', 'display_order' => 20],
            ['code' => 'ORTOPEDIA', 'name' => 'Ortopedia', 'display_order' => 30],
        ] as $item) {
            Specialty::query()->updateOrCreate(
                ['organization_id' => $organization->getKey(), 'code' => $item['code']],
                [...$item, 'is_active' => true],
            );
        }

        foreach ([
            ['code' => 'WALK_IN', 'name' => 'Meios próprios', 'requires_vehicle_data' => false, 'display_order' => 10],
            ['code' => 'SAMU', 'name' => 'SAMU', 'requires_vehicle_data' => true, 'display_order' => 20],
            ['code' => 'AMBULANCE', 'name' => 'Ambulância', 'requires_vehicle_data' => true, 'display_order' => 30],
            ['code' => 'POLICE', 'name' => 'Viatura', 'requires_vehicle_data' => true, 'display_order' => 40],
        ] as $item) {
            ArrivalMethod::query()->updateOrCreate(
                ['organization_id' => $organization->getKey(), 'code' => $item['code']],
                [...$item, 'is_active' => true],
            );
        }

        $this->flow->bootstrap($unit);
        $triageQueueId = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-TRIAGE')
            ->value('id');
        $labIntakeQueueId = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-LAB_INTAKE')
            ->value('id');

        foreach ([
            ['code' => 'EMERGENCY', 'name' => 'Urgência e emergência', 'requires_triage' => true, 'allows_provisional_patient' => true, 'display_order' => 10],
            ['code' => 'REFERRED', 'name' => 'Paciente referenciado', 'requires_triage' => true, 'allows_provisional_patient' => true, 'display_order' => 20],
            ['code' => 'RETURN', 'name' => 'Retorno', 'requires_triage' => false, 'allows_provisional_patient' => false, 'display_order' => 30],
        ] as $item) {
            EntryType::query()->updateOrCreate(
                ['organization_id' => $organization->getKey(), 'code' => $item['code']],
                [
                    ...$item,
                    'default_queue_id' => $item['requires_triage'] ? $triageQueueId : $labIntakeQueueId,
                    'is_active' => true,
                ],
            );
        }

        // Toda unidade real ja chega com CNES cadastrado no Synclab antes de
        // existir no SyncHosp (exceto a unidade de demonstracao do super
        // administrador) - o envio de requisicoes so precisa do CNES na URL,
        // sem usuario/senha, entao a integracao pode nascer pronta.
        LaboratoryIntegration::query()->firstOrCreate(
            ['health_unit_id' => $unit->getKey(), 'provider' => 'synclab'],
            [
                'organization_id' => $organization->getKey(),
                'base_url' => rtrim((string) config('sync_sus.synclab.base_url'), '/'),
                'external_tenant_code' => $unit->cnes_code,
                'is_active' => true,
                'transmission_enabled' => true,
                'connection_status' => 'configured',
            ],
        );
    }
}
