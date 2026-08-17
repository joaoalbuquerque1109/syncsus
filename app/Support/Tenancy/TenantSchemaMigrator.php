<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabaseEvent;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final readonly class TenantSchemaMigrator
{
    private const LEGACY_MIGRATION_CUTOFF = '2026_08_10_090000_create_phase_five_operational_foundation.php';

    public function __construct(private TenantConnectionManager $connections) {}

    /** @return array{connection: string, expected: list<string>, applied: list<string>, pending: list<string>, legacy_applied_count: int, signature: string, signature_mismatch: bool, status: string} */
    public function inspect(TenantDatabase $tenantDatabase): array
    {
        $connection = $this->connections->assertDedicatedConnectionAvailable($tenantDatabase);
        $expected = $this->expectedMigrations();
        $applied = Schema::connection($connection)->hasTable('migrations')
            ? DB::connection($connection)->table('migrations')->orderBy('migration')->pluck('migration')->all()
            : [];
        $applied = array_values(array_filter($applied, 'is_string'));
        $pending = array_values(array_diff($expected, $applied));
        $signature = $this->signature();
        $lastMigrationEvent = TenantDatabaseEvent::query()
            ->where('tenant_database_id', $tenantDatabase->getKey())
            ->where('action', 'schema_migrated')
            ->latest('occurred_at')
            ->first();
        $recordedContext = $lastMigrationEvent?->contextPayload() ?? [];
        $recordedSignature = $recordedContext['schema_signature'] ?? null;
        $signatureVersion = $recordedContext['signature_version'] ?? null;
        $signatureMismatch = $signatureVersion === 2
            && is_string($recordedSignature)
            && ! hash_equals($recordedSignature, $signature);

        return [
            'connection' => $connection,
            'expected' => $expected,
            'applied' => $applied,
            'pending' => $pending,
            'legacy_applied_count' => count(array_diff($applied, $expected)),
            'signature' => $signature,
            'signature_mismatch' => $signatureMismatch,
            'status' => $pending === [] && ! $signatureMismatch ? 'ready' : 'drifted',
        ];
    }

    /** @return array{connection: string, expected: list<string>, applied: list<string>, pending: list<string>, legacy_applied_count: int, signature: string, signature_mismatch: bool, status: string} */
    public function migrate(TenantDatabase $tenantDatabase, ?User $actor = null): array
    {
        $connection = $this->connections->assertDedicatedConnectionAvailable($tenantDatabase);
        Log::info('tenant.schema_migration_started', $this->logContext($tenantDatabase, $connection));

        try {
            $exitCode = Artisan::call('migrate', [
                '--database' => $connection,
                '--path' => $this->migrationPath(),
                '--force' => true,
            ]);
            if ($exitCode !== 0) {
                throw new RuntimeException('As migrations exclusivas do banco Tenant não foram concluídas.');
            }
            $result = $this->inspect($tenantDatabase);
            if ($result['pending'] !== []) {
                throw new RuntimeException('O banco Tenant permanece com migrations pendentes após a execução.');
            }
            if ($result['signature_mismatch']) {
                throw new RuntimeException(
                    'A assinatura de uma migration Tenant já aplicada foi alterada; crie uma migration incremental.',
                );
            }
            $this->record($tenantDatabase, $actor, 'schema_migrated', $result);
            Log::info('tenant.schema_migration_completed', [
                ...$this->logContext($tenantDatabase, $connection),
                'schema_signature' => $result['signature'],
            ]);

            return $result;
        } catch (Throwable $exception) {
            $this->recordFailure($tenantDatabase, $actor, $exception);
            Log::error('tenant.schema_migration_failed', [
                ...$this->logContext($tenantDatabase, $connection),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }
    }

    /** @return array{connection: string, expected: list<string>, applied: list<string>, pending: list<string>, legacy_applied_count: int, signature: string, signature_mismatch: bool, status: string} */
    public function audit(TenantDatabase $tenantDatabase, ?User $actor = null): array
    {
        try {
            $result = $this->inspect($tenantDatabase);
            $this->record($tenantDatabase, $actor, 'schema_checked', $result);

            return $result;
        } catch (Throwable $exception) {
            $this->recordFailure($tenantDatabase, $actor, $exception);
            throw $exception;
        }
    }

    /** @return list<string> */
    public function expectedMigrations(): array
    {
        $files = glob(base_path($this->migrationPath().'/*.php'));
        if ($files === false || $files === []) {
            throw new RuntimeException('Nenhuma migration Tenant foi encontrada.');
        }
        $names = array_map(
            static fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            $files,
        );
        sort($names);

        return $names;
    }

    private function migrationPath(): string
    {
        return trim((string) config('tenancy.migrations.tenant_path', 'database/migrations/tenant'), '/\\');
    }

    private function signature(): string
    {
        $parts = [];
        foreach ($this->signatureFiles() as $path) {
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                throw new RuntimeException('Não foi possível calcular a assinatura da migration Tenant.');
            }
            $parts[] = str_replace('\\', '/', $path).':'.$hash;
        }

        return hash('sha256', implode('|', $parts));
    }

    /** @return list<string> */
    private function signatureFiles(): array
    {
        $tenantFiles = glob(base_path($this->migrationPath().'/*.php')) ?: [];
        $legacyFiles = array_values(array_filter(
            glob(database_path('migrations/*.php')) ?: [],
            static fn (string $file): bool => basename($file) <= self::LEGACY_MIGRATION_CUTOFF,
        ));
        $files = [...$legacyFiles, ...$tenantFiles];
        sort($files);

        return $files;
    }

    /** @param array<string, mixed> $result */
    private function record(TenantDatabase $tenantDatabase, ?User $actor, string $action, array $result): void
    {
        DB::connection('core')->transaction(function () use ($tenantDatabase, $actor, $action, $result): void {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $record->update(['schema_status' => $result['status']]);
            TenantDatabaseEvent::query()->create([
                'tenant_database_id' => $record->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'action' => $action,
                'from_state' => $record->stateEnum()->value,
                'to_state' => $record->stateEnum()->value,
                'context' => [
                    'schema_signature' => $result['signature'],
                    'signature_version' => 2,
                    'signature_mismatch' => $result['signature_mismatch'],
                    'pending_migrations' => $result['pending'],
                    'legacy_applied_count' => $result['legacy_applied_count'],
                ],
                'occurred_at' => now(),
            ]);
        });
    }

    private function recordFailure(TenantDatabase $tenantDatabase, ?User $actor, Throwable $exception): void
    {
        DB::connection('core')->transaction(function () use ($tenantDatabase, $actor, $exception): void {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $record->update(['schema_status' => 'failed']);
            TenantDatabaseEvent::query()->create([
                'tenant_database_id' => $record->getKey(),
                'actor_user_id' => $actor?->getKey(),
                'action' => 'schema_migration_failed',
                'from_state' => $record->stateEnum()->value,
                'to_state' => $record->stateEnum()->value,
                'context' => ['exception' => $exception::class],
                'occurred_at' => now(),
            ]);
        });
    }

    /** @return array{health_unit_public_id: string, tenant_database_public_id: string, tenant_connection: string} */
    private function logContext(TenantDatabase $tenantDatabase, string $connection): array
    {
        return [
            'health_unit_public_id' => (string) $tenantDatabase->healthUnit()->value('public_id'),
            'tenant_database_public_id' => (string) $tenantDatabase->public_id,
            'tenant_connection' => $connection,
        ];
    }
}
