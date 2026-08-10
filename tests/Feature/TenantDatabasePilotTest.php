<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabaseEvent;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantDatabaseLifecycle;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use App\Support\Tenancy\TenantDatabaseReconciler;
use App\Support\Tenancy\TenantDatabaseState;
use App\Support\Tenancy\TenantPilotDataSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class TenantDatabasePilotTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_pilot_runs_through_shadow_validation_cutover_and_rollback(): void
    {
        $this->configurePilotProfile();
        $unit = $this->createHealthUnit('PHASE-3-PILOT');
        $actor = $this->createPlatformAdministrator();
        $panel = Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel piloto',
            'public_code' => 'PHASE-3-PANEL',
        ]);
        $connections = app(TenantConnectionManager::class);
        $lifecycle = app(TenantDatabaseLifecycle::class);

        $database = $lifecycle->register($unit, 'phase_3_test', null, $actor);
        $this->assertSame(TenantDatabaseState::Legacy, $database->state);
        $this->assertSame('tenant_test', $connections->connectionName($unit));

        $database = app(TenantDatabaseProvisioner::class)->provision($database, $actor);
        $this->assertSame(TenantDatabaseState::Shadow, $database->state);
        $this->assertSame('ready', $database->schema_status);
        $this->assertSame('tenant_test', $connections->connectionName($unit));
        $dedicated = $connections->dedicatedConnectionName($database);

        $counts = app(TenantPilotDataSynchronizer::class)->synchronize($database, $actor);
        $this->assertSame(1, $counts['panels']);
        $this->assertSame('Painel piloto', DB::connection($dedicated)->table('panels')->value('name'));

        $panel->update(['name' => 'Painel espelhado']);
        $this->assertSame('Painel espelhado', DB::connection($dedicated)->table('panels')->value('name'));

        $department = Department::query()->create([
            'health_unit_id' => $unit->getKey(),
            'code' => 'PILOT',
            'name' => 'Setor piloto',
            'type' => 'medical',
            'is_clinical' => true,
            'is_active' => true,
        ]);
        $queue = Queue::query()->create([
            'health_unit_id' => $unit->getKey(),
            'department_id' => $department->getKey(),
            'code' => 'PILOT-QUEUE',
            'name' => 'Fila piloto',
            'prefix' => 'P',
            'sequence_reset_policy' => 'daily',
            'priority_strategy' => 'priority_fifo',
            'minimum_calls_before_absent' => 1,
            'ticket_length' => 3,
            'is_active' => true,
        ]);

        DB::connection('tenant_test')->table('panel_queue')->insert([
            'panel_id' => $panel->getKey(),
            'queue_id' => $queue->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('panel_queue', [
            'panel_id' => $panel->getKey(),
            'queue_id' => $queue->getKey(),
        ], $dedicated);

        $result = app(TenantDatabaseReconciler::class)->reconcile($database, $actor);
        $this->assertSame('matched', $result['status']);
        $database = $database->refresh();
        $database = $lifecycle->transition($database, TenantDatabaseState::Validating, $actor);
        $database = $lifecycle->recordContinuityEvidence($database->refresh(), $actor, [
            'backup_reference' => 'backup://phase-3-pilot/verified',
            'restore_reference' => 'restore://phase-3-pilot/tested',
        ]);
        $result = app(TenantDatabaseReconciler::class)->reconcile($database, $actor);
        $this->assertSame('matched', $result['status']);
        $database = $database->refresh();
        $database = $lifecycle->transition($database, TenantDatabaseState::Cutover, $actor);
        $this->assertSame($dedicated, $connections->connectionName($unit));

        app(TenantContext::class)->reset();
        app(TenantContext::class)->resolve($unit, $connections->connectionName($unit));
        $this->assertSame('Painel espelhado', Panel::query()->sole()->name);
        app(RecordAuditEventAction::class)->execute(
            'tenant.pilot_cutover_verified',
            Request::create('/tests/tenant-pilot', 'POST'),
            $actor,
            [],
            (int) $unit->getKey(),
        );
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.pilot_cutover_verified',
            'health_unit_id' => $unit->getKey(),
        ], $dedicated);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'tenant.pilot_cutover_verified',
        ], 'tenant_test');

        $database = $lifecycle->transition($database, TenantDatabaseState::Rollback, $actor);
        $this->assertSame('tenant_test', $connections->connectionName($unit));
        $database = $lifecycle->transition($database, TenantDatabaseState::Legacy, $actor);
        $this->assertSame(TenantDatabaseState::Legacy, $database->state);
        $this->assertDatabaseHas('tenant_database_events', [
            'tenant_database_id' => $database->getKey(),
            'action' => 'state_transitioned',
            'from_state' => 'CUTOVER',
            'to_state' => 'ROLLBACK',
        ], 'core');
        $this->assertGreaterThanOrEqual(9, TenantDatabaseEvent::query()
            ->where('tenant_database_id', $database->getKey())->count());
    }

    public function test_cutover_is_blocked_without_a_clean_reconciliation(): void
    {
        $this->configurePilotProfile();
        $unit = $this->createHealthUnit('PHASE-3-GUARD');
        $actor = $this->createPlatformAdministrator();
        $lifecycle = app(TenantDatabaseLifecycle::class);
        $database = $lifecycle->register($unit, 'phase_3_test', null, $actor);
        $database = app(TenantDatabaseProvisioner::class)->provision($database, $actor);
        $database = $lifecycle->transition($database, TenantDatabaseState::Validating, $actor);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CUTOVER exige uma reconciliação VALIDATING');
        $lifecycle->transition($database, TenantDatabaseState::Cutover, $actor);
    }

    public function test_shadow_failure_does_not_rollback_primary_writes_and_is_logged(): void
    {
        $this->configurePilotProfile();
        $unit = $this->createHealthUnit('PHASE-3-SHADOW-FAILURE');
        $actor = $this->createPlatformAdministrator();
        $panel = Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel para atualizar',
            'public_code' => 'SHADOW-UPDATE',
        ]);
        $panelToDelete = Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel para excluir',
            'public_code' => 'SHADOW-DELETE',
        ]);
        $lifecycle = app(TenantDatabaseLifecycle::class);
        $database = $lifecycle->register($unit, 'phase_3_test', null, $actor);
        $database = app(TenantDatabaseProvisioner::class)->provision($database, $actor);
        $department = Department::query()->create([
            'health_unit_id' => $unit->getKey(),
            'code' => 'SHADOW-FAILURE',
            'name' => 'Setor para falha do espelho',
            'type' => 'medical',
            'is_clinical' => true,
            'is_active' => true,
        ]);
        $queue = Queue::query()->create([
            'health_unit_id' => $unit->getKey(),
            'department_id' => $department->getKey(),
            'code' => 'SHADOW-FAILURE',
            'name' => 'Fila para falha do espelho',
            'prefix' => 'S',
            'sequence_reset_policy' => 'daily',
            'priority_strategy' => 'priority_fifo',
            'minimum_calls_before_absent' => 1,
            'ticket_length' => 3,
            'is_active' => true,
        ]);
        $connections = app(TenantConnectionManager::class);
        $shadow = $connections->dedicatedConnectionName($database);
        config()->set(
            'tenancy.database_profiles.phase_3_test.database',
            storage_path('framework/testing/'.Str::ulid().'/unavailable.sqlite'),
        );
        DB::purge($shadow);
        Log::spy();

        $panel->update(['name' => 'Persistido somente no legado']);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $this->isSafeShadowFailureLog(
                $message,
                $context,
                (string) $unit->public_id,
                $shadow,
                'panels',
            ),
        )->once();

        $panelToDelete->delete();
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $this->isSafeShadowFailureLog(
                $message,
                $context,
                (string) $unit->public_id,
                $shadow,
                'panels',
            ),
        )->times(2);

        DB::connection('tenant_test')->table('panel_queue')->insert([
            'panel_id' => $panel->getKey(),
            'queue_id' => $queue->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message, array $context): bool => $this->isSafeShadowFailureLog(
                $message,
                $context,
                (string) $unit->public_id,
                $shadow,
                'panel_queue',
            ),
        )->once();
        app(TenantContext::class)->reset();

        $this->assertDatabaseHas('panels', [
            'id' => $panel->getKey(),
            'name' => 'Persistido somente no legado',
        ], 'tenant_test');
        $this->assertDatabaseMissing('panels', ['id' => $panelToDelete->getKey()], 'tenant_test');
        $this->assertDatabaseHas('panel_queue', [
            'panel_id' => $panel->getKey(),
            'queue_id' => $queue->getKey(),
        ], 'tenant_test');
    }

    public function test_registration_rejects_an_unknown_connection_profile_without_persisting_it(): void
    {
        $unit = $this->createHealthUnit('PHASE-3-PROFILE');
        $actor = $this->createPlatformAdministrator();

        try {
            app(TenantDatabaseLifecycle::class)->register($unit, 'missing-profile', null, $actor);
            $this->fail('Um perfil ausente deveria falhar de forma fechada.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('não está configurado', $exception->getMessage());
        }

        $this->assertSame(0, TenantDatabase::query()->count());
    }

    private function configurePilotProfile(): void
    {
        config([
            'tenancy.database_profiles.phase_3_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
                'transaction_mode' => 'DEFERRED',
            ],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function isSafeShadowFailureLog(
        string $message,
        array $context,
        string $unitPublicId,
        string $shadowConnection,
        string $table,
    ): bool {
        return $message === 'tenant.shadow_write_failed'
            && array_keys($context) === [
                'health_unit_public_id',
                'shadow_connection',
                'table',
                'exception',
            ]
            && $context['health_unit_public_id'] === $unitPublicId
            && $context['shadow_connection'] === $shadowConnection
            && $context['table'] === $table
            && is_string($context['exception']);
    }
}
