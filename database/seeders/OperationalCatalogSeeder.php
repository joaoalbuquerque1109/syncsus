<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\Room;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class OperationalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $organizationIds = HealthUnit::query()->distinct()->pluck('organization_id');
        foreach ($organizationIds as $organizationId) {
            $unit = HealthUnit::query()->where('organization_id', $organizationId)->firstOrFail();
            $this->activate($unit);
            foreach ([
                ['code' => 'CLINICA', 'name' => 'Clínica médica', 'display_order' => 10],
                ['code' => 'PEDIATRIA', 'name' => 'Pediatria', 'display_order' => 20],
                ['code' => 'ORTOPEDIA', 'name' => 'Ortopedia', 'display_order' => 30],
            ] as $item) {
                Specialty::query()->updateOrCreate(
                    ['organization_id' => $organizationId, 'code' => $item['code']],
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
                    ['organization_id' => $organizationId, 'code' => $item['code']],
                    [...$item, 'is_active' => true],
                );
            }
        }

        foreach ([
            ['code' => 'RED', 'name' => 'Vermelho', 'color_key' => 'red', 'reference_minutes' => 0, 'priority_weight' => 100, 'display_order' => 10],
            ['code' => 'ORANGE', 'name' => 'Laranja', 'color_key' => 'orange', 'reference_minutes' => 10, 'priority_weight' => 80, 'display_order' => 20],
            ['code' => 'YELLOW', 'name' => 'Amarelo', 'color_key' => 'yellow', 'reference_minutes' => 60, 'priority_weight' => 60, 'display_order' => 30],
            ['code' => 'GREEN', 'name' => 'Verde', 'color_key' => 'green', 'reference_minutes' => 120, 'priority_weight' => 40, 'display_order' => 40],
            ['code' => 'BLUE', 'name' => 'Azul', 'color_key' => 'blue', 'reference_minutes' => 240, 'priority_weight' => 20, 'display_order' => 50],
        ] as $item) {
            RiskLevel::query()->updateOrCreate(['code' => $item['code']], [...$item, 'protocol_version' => 'SYNC-2026.1', 'is_active' => true]);
        }

        foreach (HealthUnit::query()->get() as $unit) {
            $this->activate($unit);
            $this->seedUnit($unit);
            $this->seedPanel($unit);
        }

        foreach ([
            ['code' => 'EMERGENCY', 'name' => 'Urgência e emergência', 'requires_triage' => true, 'allows_provisional_patient' => true, 'display_order' => 10],
            ['code' => 'REFERRED', 'name' => 'Paciente referenciado', 'requires_triage' => true, 'allows_provisional_patient' => true, 'display_order' => 20],
            ['code' => 'RETURN', 'name' => 'Retorno', 'requires_triage' => false, 'allows_provisional_patient' => false, 'display_order' => 30],
        ] as $item) {
            foreach ($organizationIds as $organizationId) {
                $unit = HealthUnit::query()->where('organization_id', $organizationId)->firstOrFail();
                $this->activate($unit);
                $labIntakeQueueId = Queue::query()
                    ->where('health_unit_id', $unit->getKey())
                    ->where('code', 'QUEUE-LAB_INTAKE')
                    ->value('id');
                EntryType::query()->updateOrCreate(
                    ['organization_id' => $organizationId, 'code' => $item['code']],
                    [
                        ...$item,
                        'default_queue_id' => $item['requires_triage'] ? null : $labIntakeQueueId,
                        'is_active' => true,
                    ],
                );
            }
        }
    }

    private function seedUnit(HealthUnit $unit): void
    {
        $departments = [
            ['code' => 'RECEPTION', 'name' => 'Recepção', 'type' => 'administrative', 'is_clinical' => false, 'display_order' => 10],
            ['code' => 'TRIAGE', 'name' => 'Triagem', 'type' => 'triage', 'is_clinical' => true, 'display_order' => 20],
            ['code' => 'CLINIC', 'name' => 'Clínica médica', 'type' => 'medical', 'is_clinical' => true, 'display_order' => 30],
            ['code' => 'PEDIATRICS', 'name' => 'Pediatria', 'type' => 'medical', 'is_clinical' => true, 'display_order' => 40],
            ['code' => 'ORTHOPEDICS', 'name' => 'Ortopedia', 'type' => 'medical', 'is_clinical' => true, 'display_order' => 50],
            ['code' => 'LAB_INTAKE', 'name' => 'Recepção de exames', 'type' => 'medical', 'is_clinical' => true, 'display_order' => 60],
        ];

        foreach ($departments as $item) {
            $department = Department::query()->updateOrCreate(
                ['health_unit_id' => $unit->getKey(), 'code' => $item['code']],
                [...$item, 'is_active' => true],
            );
            $room = Room::query()->updateOrCreate(
                ['department_id' => $department->getKey(), 'code' => 'ROOM-01'],
                ['name' => $item['name'].' 01', 'room_type' => $item['type'], 'is_active' => true],
            );
            $servicePoint = ServicePoint::query()->updateOrCreate(
                ['room_id' => $room->getKey(), 'code' => 'POINT-01'],
                ['name' => 'Ponto 01', 'type' => $item['type'], 'is_active' => true],
            );

            if ($item['is_clinical']) {
                $specialtyCode = match ($item['code']) {
                    'CLINIC' => 'CLINICA',
                    'PEDIATRICS' => 'PEDIATRIA',
                    'ORTHOPEDICS' => 'ORTOPEDIA',
                    default => null,
                };
                $queue = Queue::query()->updateOrCreate(
                    ['health_unit_id' => $unit->getKey(), 'code' => 'QUEUE-'.$item['code']],
                    [
                        'department_id' => $department->getKey(),
                        'specialty_id' => $specialtyCode === null
                            ? null
                            : Specialty::query()
                                ->where('organization_id', $unit->organization_id)
                                ->where('code', $specialtyCode)
                                ->value('id'),
                        'name' => 'Fila de '.$item['name'],
                        'prefix' => match ($item['code']) {
                            'TRIAGE' => 'T',
                            'LAB_INTAKE' => 'E',
                            default => mb_substr($item['code'], 0, 1),
                        },
                        'sequence_reset_policy' => 'daily',
                        'priority_strategy' => 'priority_fifo',
                        'minimum_calls_before_absent' => 1,
                        'ticket_length' => 3,
                        'is_active' => true,
                        'display_order' => $item['display_order'],
                    ],
                );
                $queue->servicePoints()->syncWithoutDetaching([$servicePoint->getKey()]);
            }
        }
    }

    private function seedPanel(HealthUnit $unit): void
    {
        $panel = Panel::query()->updateOrCreate(
            ['health_unit_id' => $unit->getKey(), 'name' => 'Painel principal'],
            [
                'public_code' => 'p-'.substr(hash('sha256', (string) config('app.key').'|'.$unit->public_id), 0, 40),
                'identification_mode' => 'social_first_initial',
                'previous_calls_count' => 5,
                'sound_enabled' => true,
                'suggested_volume' => 80,
                'theme' => 'institutional',
                'institutional_message' => 'Aguarde a chamada pelo seu nome e dirija-se ao local indicado.',
                'is_active' => true,
            ],
        );
        $panel->queues()->sync(Queue::query()->where('health_unit_id', $unit->getKey())->pluck('id')->all());
    }

    private function activate(HealthUnit $unit): void
    {
        $context = app(TenantContext::class);
        $context->reset();
        $context->resolve($unit, app(TenantConnectionManager::class)->connectionName($unit));
    }
}
