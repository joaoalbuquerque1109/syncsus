<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\Room;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientIdentifier;
use App\Modules\Professionals\Application\Services\ProfessionalOperationalAssignments;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class QueueFlowTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_authorized_professional_can_call_recall_and_start_with_version_conflict_protection(): void
    {
        [$unit, $user, $entry, $point] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $risk = RiskLevel::query()->where('code', 'YELLOW')->sole();
        $entry->encounter->update(['risk_level_id' => $risk->getKey()]);

        $this->actingAs($user)->withSession($session)->get(route('queues.index'))
            ->assertOk()
            ->assertSee('Filas e chamadas');
        $this->actingAs($user)->withSession($session)->getJson(route('queues.entries', $entry->queue))
            ->assertOk()
            ->assertJsonPath('data.0.ticket', 'T001')
            ->assertJsonPath('data.0.risk', 'Amarelo')
            ->assertJsonPath('data.0.risk_color', 'yellow')
            ->assertJsonPath('data.0.encounter_public_id', $entry->encounter->public_id)
            ->assertJsonPath('data.0.encounter_version', $entry->encounter->lock_version)
            // O atendimento ainda esta em WaitingTriage (estagio administrativo) -
            // triage_professional so tem encounters.cancel_clinical, entao ainda
            // nao pode agir sobre ele por ali (precisa estar em InTriage e ser o
            // profissional responsavel).
            ->assertJsonPath('data.0.can_cancel_encounter', false);

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.call', $entry), [
            'version' => 1,
            'service_point' => $point->public_id,
        ])->assertOk()->assertJsonPath('entry.status', 'called')->assertJsonPath('entry.version', 2);

        $this->assertDatabaseHas('queue_calls', [
            'queue_entry_id' => $entry->getKey(),
            'call_type' => 'call',
            'call_number' => 1,
        ]);

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.recall', $entry), [
            'version' => 2,
            'service_point' => $point->public_id,
        ])->assertOk()->assertJsonPath('entry.version', 3);
        $this->assertDatabaseCount('queue_calls', 2);
        $this->assertDatabaseHas('queue_calls', ['call_type' => 'recall', 'call_number' => 2]);

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.start', $entry), [
            'version' => 3,
        ])->assertOk()->assertJsonPath('entry.status', 'in_service')->assertJsonPath('entry.version', 4);

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.start', $entry), [
            'version' => 3,
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'status' => 'in_service',
            'assigned_user_id' => $user->getKey(),
            'lock_version' => 4,
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'queue.service_started']);
    }

    public function test_absence_return_and_transfer_preserve_complete_history(): void
    {
        [$unit, $user, $entry, $point] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.call', $entry), [
            'version' => 1,
            'service_point' => $point->public_id,
        ])->assertOk();
        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.absent', $entry), [
            'version' => 2,
            'confirmation' => true,
            'reason' => 'Paciente não se apresentou.',
        ])->assertOk()->assertJsonPath('entry.status', 'absent');
        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.return', $entry), [
            'version' => 3,
            'reason' => 'Paciente localizado na recepção.',
        ])->assertOk()->assertJsonPath('entry.status', 'waiting');

        $destinationQueue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-CLINIC')->sole();
        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.transfer', $entry), [
            'version' => 4,
            'destination_queue' => $destinationQueue->public_id,
            'reason' => 'Encaminhamento operacional.',
            'preserve_priority' => true,
        ])->assertOk()->assertJsonPath('entry.status', 'waiting');

        $source = $entry->fresh();
        $this->assertSame(QueueEntryStatus::Transferred, $source?->status);
        $destination = QueueEntry::query()->where('queue_id', $destinationQueue->getKey())->sole();
        $this->assertSame($entry->priority_weight, $destination->priority_weight);
        $this->assertSame('C001', $destination->ticket_number);
        $this->assertDatabaseCount('queue_transfers', 1);
        $this->assertDatabaseHas('queue_entry_history', ['queue_entry_id' => $entry->getKey(), 'action' => 'marked_absent']);
        $this->assertDatabaseHas('queue_entry_history', ['queue_entry_id' => $entry->getKey(), 'action' => 'returned']);
        $this->assertDatabaseHas('queue_entry_history', ['queue_entry_id' => $entry->getKey(), 'action' => 'transferred']);
        $this->assertDatabaseHas('queue_entry_history', ['queue_entry_id' => $destination->getKey(), 'action' => 'entered_by_transfer']);
    }

    public function test_public_panel_calls_by_name_without_exposing_clinical_or_document_data(): void
    {
        [$unit, $user, $entry, $point, $patient] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        PatientIdentifier::query()->create([
            'patient_id' => $patient->getKey(),
            'type' => 'cpf',
            'normalized_value' => '52998224725',
            'display_value' => '529.982.247-25',
            'is_primary' => true,
        ]);
        $panel = Panel::query()->where('health_unit_id', $unit->getKey())->sole();
        $panel->update(['identification_mode' => 'full_name']);

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.call', $entry), [
            'version' => 1,
            'service_point' => $point->public_id,
        ])->assertOk();

        $state = $this->getJson(route('panels.state', $panel))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ticket', 'T001')
            ->assertJsonPath('data.0.person_label', $patient->full_name)
            ->assertJsonMissingPath('data.0.patient')
            ->assertJsonMissingPath('data.0.medical_record_number')
            ->assertJsonMissingPath('data.0.cpf')
            ->assertJsonMissingPath('data.0.risk');
        $state->assertSee($patient->full_name)
            ->assertDontSee($patient->medical_record_number)
            ->assertDontSee('52998224725');
        $cursor = $state->json('data.0.event');

        $this->actingAs($user)->withSession($session)->postJson(route('queue-entries.recall', $entry), [
            'version' => 2,
            'service_point' => $point->public_id,
        ])->assertOk();
        $this->getJson(route('panels.state', ['panel' => $panel, 'after' => $cursor]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.is_recall', true);

        $this->postJson(route('panels.heartbeat', $panel))->assertOk()->assertJsonPath('ok', true);
        $this->assertNotNull($panel->fresh()?->last_heartbeat_at);
        $this->assertSame(1, $panel->fresh()?->heartbeat_count);

        $this->postJson(route('panels.heartbeat', $panel))->assertOk();
        $this->assertSame(1, $panel->fresh()?->heartbeat_count);

        $this->travel(16)->seconds();
        $this->postJson(route('panels.heartbeat', $panel))->assertOk();
        $this->assertSame(2, $panel->fresh()?->heartbeat_count);
    }

    public function test_user_without_call_permission_cannot_change_queue_state(): void
    {
        [$unit, , $entry, $point] = $this->context();
        $receptionist = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $receptionist->assignRole('receptionist');

        $this->actingAs($receptionist)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->postJson(route('queue-entries.call', $entry), [
                'version' => 1,
                'service_point' => $point->public_id,
            ])
            ->assertForbidden();
        $this->assertDatabaseCount('queue_calls', 0);
    }

    public function test_administrator_can_configure_panel_privacy_and_associated_queues(): void
    {
        $context = $this->context();
        $unit = $context[0];
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $panel = Panel::query()->where('health_unit_id', $unit->getKey())->sole();
        $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $cacheKey = "syncsus:unit:{$unit->getKey()}:panel:{$panel->getKey()}:queue-ids";
        Cache::put($cacheKey, [-1], 60);

        $this->actingAs($administrator)->withSession($session)
            ->get(route('administration.flow.index'))
            ->assertOk()
            ->assertSee('Filas e painéis');

        $this->actingAs($administrator)->withSession($session)
            ->put(route('administration.flow.panels.update', $panel), [
                'name' => 'Painel da recepção',
                'identification_mode' => 'first_name_initial',
                'previous_calls_count' => 7,
                'sound_enabled' => true,
                'suggested_volume' => 70,
                'institutional_message' => 'Acompanhe sua senha.',
                'queues' => [$queue->getKey()],
            ])
            ->assertRedirect();

        $this->assertNull(Cache::get($cacheKey));
        $this->assertDatabaseHas('panels', [
            'id' => $panel->getKey(),
            'name' => 'Painel da recepção',
            'identification_mode' => 'first_name_initial',
            'previous_calls_count' => 7,
        ]);
        $this->assertDatabaseCount('panel_queue', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'panel.configuration_updated']);
    }

    public function test_waiting_queue_query_uses_the_compound_operational_index(): void
    {
        [$unit] = $this->context();
        $queueId = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->value('id');
        $isSqlite = DB::connection()->getDriverName() === 'sqlite';
        if (! $isSqlite) {
            $indexes = collect(DB::select('SHOW INDEX FROM queue_entries'))
                ->pluck('Key_name')
                ->unique()
                ->join(' ');
            $this->assertStringContainsString(
                'queue_entries_queue_id_status_priority_weight_entered_at_index',
                $indexes,
            );

            return;
        }
        $plan = DB::select(
            'EXPLAIN QUERY PLAN SELECT id, ticket_number, priority_weight, entered_at
             FROM queue_entries
             WHERE queue_id = ? AND status = ?
             ORDER BY priority_weight DESC, entered_at ASC
             LIMIT 100',
            [$queueId, 'waiting'],
        );
        $details = collect($plan)->map(fn (object $row): string => (string) $row->detail)->join(' ');

        $this->assertStringContainsString(
            'queue_entries_queue_id_status_priority_weight_entered_at_index',
            $details,
        );
    }

    public function test_queue_index_query_count_is_constant_for_broad_access(): void
    {
        [$unit] = $this->context();
        Queue::query()->where('health_unit_id', $unit->getKey())->update(['is_active' => false]);
        $fixture = $this->performanceQueues($unit);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)->get(route('queues.index'))->assertOk();

        $this->activateQueues($fixture['queues'], 3);
        [$threeQueues, $threeQueryCount] = $this->measuredQueueIndex($manager, $unit);
        $threeQueues->assertOk()
            ->assertSee('Fila de desempenho 01')
            ->assertSee('Ponto de desempenho 01 A')
            ->assertViewHas('queues', fn (Collection $queues): bool => $queues->count() === 3
                && $queues->every(fn (Queue $queue): bool => $queue->relationLoaded('servicePoints')
                    && $queue->servicePoints->count() === 2
                    && $queue->servicePoints->every(fn (ServicePoint $point): bool => $point->relationLoaded('room'))));

        $this->activateQueues($fixture['queues'], 8);
        [$eightQueues, $eightQueryCount] = $this->measuredQueueIndex($manager, $unit);
        $eightQueues->assertOk()
            ->assertSee('Fila de desempenho 08')
            ->assertViewHas('queues', fn (Collection $queues): bool => $queues->count() === 8
                && $queues->every(fn (Queue $queue): bool => $queue->servicePoints->count() === 2));

        $this->assertLessThanOrEqual(8, $threeQueryCount);
        $this->assertSame($threeQueryCount, $eightQueryCount);
    }

    public function test_queue_index_eager_load_preserves_restricted_professional_visibility(): void
    {
        [$unit, $professionalUser] = $this->context();
        Queue::query()->where('health_unit_id', $unit->getKey())->update(['is_active' => false]);
        $fixture = $this->performanceQueues($unit);
        $profile = $professionalUser->professionalProfile()->sole();
        $allowedIndexes = [0, 2, 7];
        app(ProfessionalOperationalAssignments::class)->sync(
            $profile,
            collect($allowedIndexes)
                ->map(fn (int $index): int => (int) $fixture['queues'][$index]->getKey())
                ->all(),
            collect($allowedIndexes)
                ->map(fn (int $index): int => (int) $fixture['primaryPoints'][$index]->getKey())
                ->all(),
        );
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($professionalUser)->withSession($session)->get(route('queues.index'))->assertOk();

        $this->activateQueues($fixture['queues'], 3);
        [$threeQueues, $threeQueryCount] = $this->measuredQueueIndex($professionalUser, $unit);
        $threeQueues->assertOk()
            ->assertSee('Fila de desempenho 01')
            ->assertSee('Fila de desempenho 03')
            ->assertDontSee('Fila de desempenho 02')
            ->assertSee('Ponto de desempenho 01 A')
            ->assertDontSee('Ponto de desempenho 01 B')
            ->assertViewHas('queues', fn (Collection $queues): bool => $queues->pluck('code')->all() === [
                'PERF-QUEUE-01',
                'PERF-QUEUE-03',
            ] && $queues->every(fn (Queue $queue): bool => $queue->servicePoints->count() === 1
                && $queue->servicePoints->first()?->name === sprintf(
                    'Ponto de desempenho %02d A',
                    (int) str_replace('PERF-QUEUE-', '', $queue->code),
                )));

        $this->activateQueues($fixture['queues'], 8);
        [$eightQueues, $eightQueryCount] = $this->measuredQueueIndex($professionalUser, $unit);
        $eightQueues->assertOk()
            ->assertSee('Fila de desempenho 08')
            ->assertDontSee('Fila de desempenho 07')
            ->assertViewHas('queues', fn (Collection $queues): bool => $queues->pluck('code')->all() === [
                'PERF-QUEUE-01',
                'PERF-QUEUE-03',
                'PERF-QUEUE-08',
            ] && $queues->every(fn (Queue $queue): bool => $queue->servicePoints->count() === 1));

        $this->assertLessThanOrEqual(8, $threeQueryCount);
        $this->assertSame($threeQueryCount, $eightQueryCount);
    }

    /**
     * @return array{
     *     queues: Collection<int, Queue>,
     *     primaryPoints: Collection<int, ServicePoint>
     * }
     */
    private function performanceQueues(HealthUnit $unit): array
    {
        $department = Department::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'TRIAGE')
            ->sole();
        $queues = new Collection;
        $primaryPoints = new Collection;

        foreach (range(1, 8) as $index) {
            $room = Room::query()->create([
                'department_id' => $department->getKey(),
                'code' => sprintf('PERF-ROOM-%02d', $index),
                'name' => sprintf('Sala de desempenho %02d', $index),
                'room_type' => 'triage',
                'is_active' => true,
            ]);
            $primaryPoint = ServicePoint::query()->create([
                'room_id' => $room->getKey(),
                'code' => 'PERF-A',
                'name' => sprintf('Ponto de desempenho %02d A', $index),
                'type' => 'triage',
                'is_active' => true,
            ]);
            $secondaryPoint = ServicePoint::query()->create([
                'room_id' => $room->getKey(),
                'code' => 'PERF-B',
                'name' => sprintf('Ponto de desempenho %02d B', $index),
                'type' => 'triage',
                'is_active' => true,
            ]);
            $queue = Queue::query()->create([
                'health_unit_id' => $unit->getKey(),
                'department_id' => $department->getKey(),
                'code' => sprintf('PERF-QUEUE-%02d', $index),
                'name' => sprintf('Fila de desempenho %02d', $index),
                'prefix' => 'P',
                'sequence_reset_policy' => 'daily',
                'priority_strategy' => 'priority_fifo',
                'minimum_calls_before_absent' => 1,
                'ticket_length' => 3,
                'is_active' => false,
                'display_order' => 100 + $index,
            ]);
            $queue->servicePoints()->attach([$primaryPoint->getKey(), $secondaryPoint->getKey()]);
            $queues->push($queue);
            $primaryPoints->push($primaryPoint);
        }

        return ['queues' => $queues, 'primaryPoints' => $primaryPoints];
    }

    /** @param Collection<int, Queue> $queues */
    private function activateQueues(Collection $queues, int $activeCount): void
    {
        $queues->each(function (Queue $queue, int $index) use ($activeCount): void {
            $queue->update(['is_active' => $index < $activeCount]);
        });
    }

    /** @return array{0: TestResponse, 1: int} */
    private function measuredQueueIndex(User $user, HealthUnit $unit): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->actingAs($user)
                ->withSession(['active_health_unit_id' => $unit->getKey()])
                ->get(route('queues.index'));
            $queryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }

        return [$response, $queryCount];
    }

    /** @return array{0: mixed, 1: mixed, 2: QueueEntry, 3: mixed, 4: Patient} */
    private function context(): array
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $user->assignRole('triage_professional');
        $this->registerTriageProfessional($user, $unit);

        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000001',
            'full_name' => 'Paciente Sigiloso da Silva',
            'normalized_name' => 'PACIENTE SIGILOSO DA SILVA',
            'birth_date' => '1985-04-12',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
        $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0001',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::WaitingTriage,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(15),
            'registration_at' => now()->subMinutes(15),
            'current_department_id' => $queue->department_id,
            'created_by' => $user->getKey(),
        ]);
        $entry = QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'T001',
            'priority_weight' => 10,
            'status' => QueueEntryStatus::Waiting,
            'entered_at' => now()->subMinutes(15),
        ]);

        return [$unit, $user, $entry, $queue->servicePoints()->sole(), $patient];
    }
}
