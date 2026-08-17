<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabaseEvent;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantDatabaseLifecycle;
use App\Support\Tenancy\TenantSchemaHardener;
use App\Support\Tenancy\TenantSchemaMigrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class CoreUnitDatabasePhase6Test extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_test_harness_builds_physically_isolated_core_and_tenant_schemas(): void
    {
        $this->assertTrue(Schema::connection('core')->hasTable('organizations'));
        $this->assertTrue(Schema::connection('core')->hasTable('patients'));
        $this->assertTrue(Schema::connection('tenant_test')->hasTable('encounters'));
        $this->assertTrue(Schema::connection('tenant_test')->hasTable('audit_logs'));
        $this->assertFalse(Schema::connection('tenant_test')->hasTable('organizations'));
        $this->assertFalse(Schema::connection('tenant_test')->hasTable('users'));
        $this->assertFalse(Schema::connection('tenant_test')->hasTable('patients'));
        $this->assertFalse(Schema::connection('tenant_test')->hasTable('security_audit_logs'));
        $crossForeignKeys = array_values(array_filter(
            Schema::connection('tenant_test')->getForeignKeys('encounters'),
            static fn (array $foreignKey): bool => in_array(
                $foreignKey['foreign_table'],
                TenantSchemaHardener::CORE_TABLES,
                true,
            ),
        ));
        $this->assertSame([], $crossForeignKeys, json_encode($crossForeignKeys, JSON_PRETTY_PRINT));
        $this->assertDatabaseHas('migrations', [
            'migration' => '2026_08_11_000000_create_tenant_schema_baseline',
        ], 'tenant_test');
    }

    public function test_schema_migrator_detects_drift_and_applies_only_tenant_migrations(): void
    {
        // O perfil simulado do banco piloto é sempre SQLite; TenantConnectionManager
        // exige que LEGACY e o banco piloto usem o mesmo driver durante o double-write
        // (guard real, não bug). Sob o job de CI que roda a suíte contra MySQL, a
        // conexão tenant_test (LEGACY nos testes) não é sqlite, então o cenário não se
        // aplica aqui — precisaria de um schema MySQL dedicado à parte para simular o
        // piloto, fora do escopo desta correção.
        if (config('database.connections.tenant_test.driver') !== 'sqlite') {
            $this->markTestSkipped('Simulação de banco piloto usa SQLite; LEGACY nesta execução é MySQL.');
        }

        config(['tenancy.database_profiles.phase_6_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
            'transaction_mode' => 'DEFERRED',
        ]]);
        $unit = $this->createHealthUnit('PHASE-6-SCHEMA');
        $actor = $this->createPlatformAdministrator();
        $database = app(TenantDatabaseLifecycle::class)->register($unit, 'phase_6_test', null, $actor);
        $migrator = app(TenantSchemaMigrator::class);

        $before = $migrator->inspect($database);
        $this->assertSame('drifted', $before['status']);
        $this->assertSame(['2026_08_11_000000_create_tenant_schema_baseline'], $before['pending']);

        $after = $migrator->migrate($database, $actor);
        $this->assertSame('ready', $after['status']);
        $this->assertSame([], $after['pending']);
        $connection = app(TenantConnectionManager::class)->dedicatedConnectionName($database);
        $this->assertTrue(Schema::connection($connection)->hasTable('encounters'));
        $this->assertFalse(Schema::connection($connection)->hasTable('organizations'));
        $this->assertFalse(Schema::connection($connection)->hasTable('patients'));
        $this->assertDatabaseHas('tenant_database_events', [
            'tenant_database_id' => $database->getKey(),
            'action' => 'schema_migrated',
        ], 'core');

        DB::connection($connection)->table('migrations')
            ->where('migration', '2026_08_11_000000_create_tenant_schema_baseline')
            ->delete();
        $drift = $migrator->audit($database);
        $this->assertSame('drifted', $drift['status']);
        $this->assertSame('drifted', $database->refresh()->schema_status);
        $this->assertSame(1, TenantDatabaseEvent::query()
            ->where('tenant_database_id', $database->getKey())
            ->where('action', 'schema_checked')
            ->count());

        $database->update(['state' => 'TENANT']);
        $this->artisan('tenant:schema', ['unit' => $unit->public_id])
            ->expectsOutputToContain('drifted')
            ->assertSuccessful();
        $this->artisan('tenant:schema', [
            'unit' => $unit->public_id,
            '--apply' => true,
            '--actor' => $actor->public_id,
        ])->expectsOutputToContain('ready')->assertSuccessful();
        $this->assertSame('ready', $database->refresh()->schema_status);

        $lastMigration = TenantDatabaseEvent::query()
            ->where('tenant_database_id', $database->getKey())
            ->where('action', 'schema_migrated')
            ->latest('occurred_at')
            ->firstOrFail();
        $lastMigration->update(['context' => [
            'signature_version' => 2,
            'schema_signature' => str_repeat('0', 64),
        ]]);
        $tampered = $migrator->audit($database);
        $this->assertTrue($tampered['signature_mismatch']);
        $this->assertSame('drifted', $tampered['status']);
        try {
            $migrator->migrate($database, $actor);
            $this->fail('Uma migration aplicada com assinatura alterada deveria falhar fechada.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('migration incremental', $exception->getMessage());
        }
    }

    public function test_core_copy_cleanup_fails_closed_when_data_exists(): void
    {
        $connection = 'phase_6_non_empty_core_copy';
        config(["database.connections.{$connection}" => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge($connection);
        Schema::connection($connection)->create('organizations', function ($table): void {
            $table->id();
            $table->string('name');
        });
        DB::connection($connection)->table('organizations')->insert(['name' => 'Não excluir']);

        try {
            app(TenantSchemaHardener::class)->removeEmptyCoreCopies($connection);
            $this->fail('Uma cópia Core com dados deveria interromper o isolamento do schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('organizations', $exception->getMessage());
        }

        $this->assertTrue(Schema::connection($connection)->hasTable('organizations'));
        $this->assertSame(1, DB::connection($connection)->table('organizations')->count());
        DB::purge($connection);
    }
}
