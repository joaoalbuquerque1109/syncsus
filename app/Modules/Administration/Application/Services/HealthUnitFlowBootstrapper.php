<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Room;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;

final class HealthUnitFlowBootstrapper
{
    public function bootstrap(HealthUnit $unit): void
    {
        $definitions = [
            ['code' => 'RECEPTION', 'name' => 'Recepcao', 'type' => 'administrative', 'clinical' => false, 'order' => 10],
            ['code' => 'TRIAGE', 'name' => 'Triagem', 'type' => 'triage', 'clinical' => true, 'order' => 20],
            ['code' => 'CLINIC', 'name' => 'Clinica medica', 'type' => 'medical', 'clinical' => true, 'order' => 30, 'specialty' => 'CLINICA'],
        ];
        $queueIds = [];
        foreach ($definitions as $definition) {
            $department = Department::query()->updateOrCreate(
                ['health_unit_id' => $unit->getKey(), 'code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'type' => $definition['type'],
                    'is_clinical' => $definition['clinical'],
                    'is_active' => true,
                    'display_order' => $definition['order'],
                ],
            );
            $room = Room::query()->updateOrCreate(
                ['department_id' => $department->getKey(), 'code' => 'ROOM-01'],
                ['name' => $definition['name'].' 01', 'room_type' => $definition['type'], 'is_active' => true],
            );
            $point = ServicePoint::query()->updateOrCreate(
                ['room_id' => $room->getKey(), 'code' => 'POINT-01'],
                ['name' => 'Ponto 01', 'type' => $definition['type'], 'is_active' => true],
            );
            if (! $definition['clinical']) {
                continue;
            }
            $queue = Queue::query()->updateOrCreate(
                ['health_unit_id' => $unit->getKey(), 'code' => 'QUEUE-'.$definition['code']],
                [
                    'department_id' => $department->getKey(),
                    'specialty_id' => isset($definition['specialty'])
                        ? Specialty::query()
                            ->where('organization_id', $unit->organization_id)
                            ->where('code', $definition['specialty'])
                            ->value('id')
                        : null,
                    'name' => 'Fila de '.$definition['name'],
                    'prefix' => $definition['type'] === 'triage' ? 'T' : 'M',
                    'ticket_length' => 3,
                    'sequence_reset_policy' => 'daily',
                    'priority_strategy' => 'priority_fifo',
                    'minimum_calls_before_absent' => 1,
                    'is_active' => true,
                    'display_order' => $definition['order'],
                ],
            );
            $queue->servicePoints()->syncWithoutDetaching([$point->getKey()]);
            $queueIds[] = $queue->getKey();
        }

        $panel = Panel::query()->firstOrCreate(
            ['health_unit_id' => $unit->getKey(), 'name' => 'Painel principal'],
            [
                'public_code' => 'p-'.substr(hash('sha256', (string) config('app.key').'|'.$unit->public_id), 0, 40),
                'identification_mode' => 'ticket_only',
                'previous_calls_count' => 5,
                'sound_enabled' => true,
                'suggested_volume' => 80,
                'theme' => 'institutional',
                'institutional_message' => 'Aguarde sua senha e dirija-se ao local indicado.',
                'is_active' => true,
            ],
        );
        $panel->queues()->syncWithoutDetaching($queueIds);
    }
}
