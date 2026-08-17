<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Audit\Application\Services\MigrateTenantSecurityAuditHistory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SecurityAuditHistoryMigrationTest extends TestCase
{
    private string $databasePath;

    private string $backupPath;

    protected function setUp(): void
    {
        parent::setUp();
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $this->databasePath = $directory.'/security-audit-'.Str::ulid().'.sqlite';
        $this->backupPath = $directory.'/security-audit-backup-'.Str::ulid().'.sqlite';
        touch($this->databasePath);
        touch($this->backupPath);

        $connection = [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ];
        config([
            'database.default' => 'tenant_test',
            'database.connections.core' => $connection,
            'database.connections.tenant_test' => $connection,
            'tenancy.legacy_connection' => 'tenant_test',
        ]);
        DB::purge('core');
        DB::purge('tenant_test');
        // Migra pela conexão 'core' (mesmo arquivo físico) para que os passos condicionados
        // a essa conexão, como a criação de security_audit_logs, sejam executados.
        $this->artisan('migrate:fresh', ['--database' => 'core', '--force' => true])
            ->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if (app()->bound(TenantContext::class)) {
            app(TenantContext::class)->reset();
        }
        foreach (['core', 'tenant_test', 'audit_history_recovery_source_test', 'audit_guard_test'] as $connection) {
            DB::purge($connection);
        }
        foreach ([$this->databasePath, $this->backupPath] as $path) {
            @unlink($path);
            @unlink($path.'-shm');
            @unlink($path.'-wal');
        }
        parent::tearDown();
    }

    public function test_split_migration_does_not_delete_security_events_on_a_non_core_connection(): void
    {
        $guardPath = storage_path('framework/testing/security-audit-guard-'.Str::ulid().'.sqlite');
        touch($guardPath);
        config(['database.connections.audit_guard_test' => [
            'driver' => 'sqlite',
            'database' => $guardPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('audit_guard_test');

        // Recria o schema pré-split (sem correlation_id), como uma unidade cujo histórico
        // real foi copiado antes desta migration rodar naquela conexão.
        Schema::connection('audit_guard_test')->create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('health_unit_id')->nullable();
            $table->string('action', 100)->index();
            $table->nullableMorphs('auditable');
            $table->json('changed_fields')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
        DB::connection('audit_guard_test')->table('audit_logs')->insert([
            $this->rawAuditLog('user.logged_in'),
            $this->rawAuditLog('encounter.finalized'),
        ]);

        $previousDefault = config('database.default');
        config(['database.default' => 'audit_guard_test']);
        try {
            $migration = require base_path('database/migrations/2026_08_10_080000_split_security_audit_logs.php');
            $migration->up();
        } finally {
            config(['database.default' => $previousDefault]);
        }

        $this->assertSame(2, DB::connection('audit_guard_test')->table('audit_logs')->count());
        $this->assertTrue(
            DB::connection('audit_guard_test')->table('audit_logs')->where('action', 'user.logged_in')->exists(),
        );
        $this->assertFalse(Schema::connection('audit_guard_test')->hasTable('security_audit_logs'));

        DB::purge('audit_guard_test');
        @unlink($guardPath);
    }

    public function test_migrate_security_history_copies_tenant_events_to_core_and_removes_them(): void
    {
        DB::connection('tenant_test')->table('audit_logs')->insert([
            $this->rawAuditLog('user.logged_in'),
            $this->rawAuditLog('encounter.finalized'),
        ]);

        $result = app(MigrateTenantSecurityAuditHistory::class)
            ->execute('tenant_test', apply: true, deleteAfterCopy: true);

        $this->assertSame(['found' => 1, 'already_present' => 0, 'migrated' => 1], $result);
        $this->assertSame(1, DB::connection('core')->table('security_audit_logs')->count());
        $this->assertSame(1, DB::connection('tenant_test')->table('audit_logs')->count());
        $this->assertFalse(
            DB::connection('tenant_test')->table('audit_logs')->where('action', 'user.logged_in')->exists(),
        );

        $repeat = app(MigrateTenantSecurityAuditHistory::class)
            ->execute('tenant_test', apply: true, deleteAfterCopy: true);
        $this->assertSame(['found' => 0, 'already_present' => 0, 'migrated' => 0], $repeat);
        $this->assertSame(1, DB::connection('core')->table('security_audit_logs')->count());
    }

    public function test_migrate_security_history_from_backup_never_deletes_the_source(): void
    {
        $pdo = new \PDO('sqlite:'.$this->backupPath);
        $pdo->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT,
            user_id INTEGER,
            health_unit_id INTEGER,
            action TEXT,
            auditable_type TEXT,
            auditable_id INTEGER,
            changed_fields TEXT,
            context TEXT,
            ip_address TEXT,
            user_agent TEXT,
            occurred_at TEXT,
            created_at TEXT,
            updated_at TEXT
        )');
        $publicId = (string) Str::ulid();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (public_id, user_id, health_unit_id, action, occurred_at, created_at, updated_at)'
            .' VALUES (:public_id, 1, 1, :action, :occurred_at, :occurred_at, :occurred_at)',
        );
        $stmt->execute([
            'public_id' => $publicId,
            'action' => 'tenant.cutover_verified',
            'occurred_at' => now()->toDateTimeString(),
        ]);
        unset($pdo);

        config(['database.connections.audit_history_recovery_source_test' => [
            'driver' => 'sqlite',
            'database' => $this->backupPath,
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        DB::purge('audit_history_recovery_source_test');

        $result = app(MigrateTenantSecurityAuditHistory::class)
            ->execute('audit_history_recovery_source_test', apply: true, deleteAfterCopy: false);

        $this->assertSame(['found' => 1, 'already_present' => 0, 'migrated' => 1], $result);
        $recovered = DB::connection('core')->table('security_audit_logs')
            ->where('public_id', $publicId)->first();
        $this->assertNotNull($recovered);
        $this->assertSame('tenant.cutover_verified', $recovered->action);
        $this->assertNotNull($recovered->correlation_id);

        $this->assertTrue(
            DB::connection('audit_history_recovery_source_test')->table('audit_logs')
                ->where('public_id', $publicId)->exists(),
        );
    }

    /** @return array<string, mixed> */
    private function rawAuditLog(string $action): array
    {
        return [
            'public_id' => (string) Str::ulid(),
            'action' => $action,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
