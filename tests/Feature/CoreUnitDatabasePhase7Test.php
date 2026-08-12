<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Application\Jobs\ProvisionTenantDatabaseJob;
use App\Modules\Administration\Application\Services\OrganizationCatalogBootstrapper;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabaseEvent;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Operations\Infrastructure\Eloquent\BackupVerification;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantDatabaseAutoProvisioner;
use App\Support\Tenancy\TenantDatabaseLifecycle;
use App\Support\Tenancy\TenantDatabaseProvisioner;
use App\Support\Tenancy\TenantDatabaseState;
use App\Support\Tenancy\TenantPilotDataSynchronizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class CoreUnitDatabasePhase7Test extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    /** @var list<array{connection: string, path: string}> */
    private array $nativeDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->nativeDatabases as $database) {
            DB::purge($database['connection']);
            @unlink($database['path']);
            @unlink($database['path'].'-shm');
            @unlink($database['path'].'-wal');
        }

        parent::tearDown();
    }

    public function test_native_provisioning_pauses_for_real_continuity_and_explicit_cutover_gate(): void
    {
        [$unit, $actor, $database, $connection] = $this->nativeDatabase('PHASE-7-NATIVE');
        $provisioner = app(TenantDatabaseAutoProvisioner::class);

        $waitingContinuity = $provisioner->advance($database);

        $this->assertSame([
            'status' => 'waiting_continuity',
            'state' => 'VALIDATING',
            'next_step' => 'record_continuity',
        ], $waitingContinuity);
        $this->assertFalse($unit->refresh()->is_active);
        $this->assertTrue(Schema::connection($connection)->hasTable('encounters'));
        $this->assertFalse(Schema::connection($connection)->hasTable('organizations'));
        $this->assertSame(4, DB::connection($connection)->table('queues')->count());
        $this->assertSame(1, TenantDatabaseEvent::query()
            ->where('tenant_database_id', $database->getKey())
            ->where('action', 'auto_provisioning_waiting_continuity')
            ->count());

        $provisioner->advance($database->refresh());
        $this->assertSame(1, TenantDatabaseEvent::query()
            ->where('tenant_database_id', $database->getKey())
            ->where('action', 'auto_provisioning_waiting_continuity')
            ->count());

        try {
            app(TenantDatabaseLifecycle::class)->recordContinuityEvidence(
                $database->refresh(),
                $actor,
                ['backup_reference' => 'not-verified', 'restore_reference' => 'restore://phase-7/tested'],
            );
            $this->fail('Unidade nativa não deveria aceitar uma referência de backup não verificada.');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('verificação de backup', $exception->getMessage());
        }
        $verification = BackupVerification::query()->create([
            'tenant_database_id' => $database->getKey(),
            'health_unit_id' => $unit->getKey(),
            'backup_scope' => 'tenant',
            'backup_set' => 'phase-7-verified',
            'status' => 'completed',
            'restore_compatible' => true,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        Queue::fake();
        $this->artisan('tenant:record-continuity', [
            'unit' => $unit->public_id,
            'backup-verification' => $verification->public_id,
            'restore-reference' => 'restore://phase-7/tested',
            'actor' => $actor->public_id,
            '--apply' => true,
        ])->expectsOutputToContain('Continuidade registrada')->assertSuccessful();
        Queue::assertPushed(ProvisionTenantDatabaseJob::class, fn (ProvisionTenantDatabaseJob $job): bool => $job->healthUnitPublicId === $unit->public_id);
        $database = $database->refresh();
        $waitingCutover = $provisioner->advance($database);
        $this->assertSame('waiting_cutover_approval', $waitingCutover['status']);
        $this->assertSame(TenantDatabaseState::Validating, $database->refresh()->stateEnum());
        $this->assertFalse($unit->refresh()->is_active);

        config(['tenancy.native_provisioning.automatic_cutover_enabled' => true]);
        $completed = $provisioner->advance($database->refresh());
        $this->assertSame(['status' => 'complete', 'state' => 'TENANT', 'next_step' => 'none'], $completed);
        $this->assertSame(TenantDatabaseState::Tenant, $database->refresh()->stateEnum());
        $this->assertTrue($unit->refresh()->is_active);
        $this->assertSame('none', $provisioner->nextStep($database->refresh()));
    }

    public function test_divergent_shadow_remains_in_shadow_for_human_reconciliation(): void
    {
        [, $actor, $database, $connection] = $this->nativeDatabase('PHASE-7-DIVERGED');
        $database = app(TenantDatabaseProvisioner::class)->provision($database, $actor);
        app(TenantPilotDataSynchronizer::class)->synchronize($database, $actor);
        DB::connection($connection)->table('number_sequences')->insert([
            'scope' => 'rogue',
            'date_key' => '',
            'current_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(TenantDatabaseAutoProvisioner::class)->advance($database->refresh());

        $this->assertSame('diverged', $result['status']);
        $this->assertSame('reconcile_shadow', $result['next_step']);
        $this->assertSame(TenantDatabaseState::Shadow, $database->refresh()->stateEnum());
        $this->assertSame('diverged', $database->last_reconciliation_status);
        $this->assertDatabaseHas('tenant_database_events', [
            'tenant_database_id' => $database->getKey(),
            'action' => 'auto_provisioning_diverged',
        ], 'core');
    }

    /** @return array{HealthUnit, User, TenantDatabase, string} */
    private function nativeDatabase(string $code): array
    {
        $unit = $this->createHealthUnit($code);
        $unit->update(['is_active' => false]);
        app(OrganizationCatalogBootstrapper::class)->bootstrap($unit->organization, $unit);
        $actor = $this->createPlatformAdministrator();
        $database = app(TenantDatabaseLifecycle::class)->registerNative($unit, $actor);
        $database->update(['infrastructure_status' => 'grants_applied']);
        config([
            'tenancy.native_provisioning.runtime_connection' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
                'transaction_mode' => 'DEFERRED',
            ],
            'tenancy.native_provisioning.automatic_cutover_enabled' => false,
        ]);
        $path = base_path((string) $database->database_name);
        touch($path);
        $connection = app(TenantConnectionManager::class)->dedicatedConnectionName($database->refresh());
        $this->nativeDatabases[] = ['connection' => $connection, 'path' => $path];

        return [$unit, $actor, $database->refresh(), $connection];
    }
}
