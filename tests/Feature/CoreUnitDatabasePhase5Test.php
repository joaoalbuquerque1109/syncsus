<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Operations\Application\Services\BackupSetVerifier;
use App\Modules\Reports\Application\Jobs\RefreshUnitReportSnapshotJob;
use App\Modules\Reports\Application\Queries\NetworkOperationalSnapshotQuery;
use App\Modules\Reports\Application\Queries\OperationalDashboardQuery;
use App\Modules\Reports\Infrastructure\Eloquent\UnitReportSnapshot;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class CoreUnitDatabasePhase5Test extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_unit_snapshot_is_generated_outside_http_and_read_from_core(): void
    {
        $unit = $this->createHealthUnit('PHASE-5-SNAPSHOT');
        Log::spy();

        $job = new RefreshUnitReportSnapshotJob((string) $unit->public_id);
        $job->handle(
            app(TenantContext::class),
            app(TenantConnectionManager::class),
            app(OperationalDashboardQuery::class),
        );

        $snapshot = UnitReportSnapshot::query()->sole();
        $this->assertSame($unit->getKey(), $snapshot->health_unit_id);
        $this->assertSame('tenant_test', $snapshot->source_connection);
        $this->assertSame(0, $snapshot->metrics['waiting_triage']);
        $this->assertFalse(app(TenantContext::class)->isResolved());
        $this->assertDatabaseHas('unit_report_snapshots', [
            'health_unit_id' => $unit->getKey(),
            'health_unit_public_id' => $unit->public_id,
        ], 'core');

        $network = app(NetworkOperationalSnapshotQuery::class)->current();
        $this->assertCount(1, $network);
        $this->assertSame($unit->public_id, $network[0]['unit']->public_id);
        $this->assertFalse($network[0]['stale']);
        Log::shouldHaveReceived('info')->with('tenant.report_snapshot_started', \Mockery::type('array'))->once();
        Log::shouldHaveReceived('info')->with('tenant.report_snapshot_completed', \Mockery::type('array'))->once();
    }

    public function test_tenant_backup_requires_matching_unit_and_compatible_restore_point(): void
    {
        $unit = $this->createHealthUnit('PHASE-5-BACKUP');
        $database = TenantDatabase::query()->create([
            'health_unit_id' => $unit->getKey(),
            'connection_profile' => 'phase-5-test',
            'state' => 'TENANT',
            'schema_status' => 'ready',
            'infrastructure_status' => 'external',
        ]);
        $root = storage_path('framework/phase-5-backups-'.Str::lower((string) Str::ulid()));
        $set = $root.DIRECTORY_SEPARATOR.'tenant-compatible';
        File::ensureDirectoryExists($set);
        config(['sync_sus.backup_path' => $root]);

        try {
            file_put_contents($set.DIRECTORY_SEPARATOR.'database.sql.gz', gzencode("tenant database\n", 9));
            file_put_contents($set.DIRECTORY_SEPARATOR.'private-files.tar.gz', gzencode("tenant files\n", 9));
            $databaseHash = hash_file('sha256', $set.DIRECTORY_SEPARATOR.'database.sql.gz');
            $filesHash = hash_file('sha256', $set.DIRECTORY_SEPARATOR.'private-files.tar.gz');
            file_put_contents(
                $set.DIRECTORY_SEPARATOR.'SHA256SUMS',
                "{$databaseHash}  database.sql.gz\n{$filesHash}  private-files.tar.gz\n",
            );
            file_put_contents($set.DIRECTORY_SEPARATOR.'TENANT_BACKUP.json', json_encode([
                'tenant_database_public_id' => $database->public_id,
                'health_unit_public_id' => $unit->public_id,
                'core_reference_at' => now()->subMinute()->toIso8601String(),
                'restore_point_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));

            $verification = app(BackupSetVerifier::class)->verify($set, null, $database);
            $this->assertSame('tenant', $verification->backup_scope);
            $this->assertTrue($verification->restore_compatible);
            $this->assertSame($database->getKey(), $verification->tenant_database_id);

            file_put_contents($set.DIRECTORY_SEPARATOR.'TENANT_BACKUP.json', json_encode([
                'tenant_database_public_id' => $database->public_id,
                'health_unit_public_id' => $unit->public_id,
                'core_reference_at' => '',
                'restore_point_at' => '',
            ], JSON_THROW_ON_ERROR));
            try {
                app(BackupSetVerifier::class)->verify($set, null, $database);
                $this->fail('Backup Tenant sem referências temporais deveria ser rejeitado.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('temporais', $exception->getMessage());
            }

            file_put_contents($set.DIRECTORY_SEPARATOR.'TENANT_BACKUP.json', json_encode([
                'tenant_database_public_id' => $database->public_id,
                'health_unit_public_id' => $unit->public_id,
                'core_reference_at' => now()->toIso8601String(),
                'restore_point_at' => now()->subHour()->toIso8601String(),
            ], JSON_THROW_ON_ERROR));
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('anterior à referência Core');
            app(BackupSetVerifier::class)->verify($set, null, $database);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
