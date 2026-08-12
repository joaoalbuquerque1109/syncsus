<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabaseEvent;
use App\Modules\Audit\Application\Services\AuditContextSanitizer;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Operations\Infrastructure\Eloquent\BackupVerification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final readonly class TenantDatabaseLifecycle
{
    public function __construct(
        private TenantConnectionManager $connections,
        private AuditContextSanitizer $sanitizer,
    ) {}

    public function register(
        HealthUnit $unit,
        string $connectionProfile,
        ?string $databaseName,
        User $actor,
    ): TenantDatabase {
        $this->authorize($unit, $actor);
        $profile = config("tenancy.database_profiles.{$connectionProfile}");
        if (! is_array($profile)) {
            throw new LogicException("O perfil de conexão '{$connectionProfile}' não está configurado.");
        }

        return DB::connection('core')->transaction(function () use (
            $unit,
            $connectionProfile,
            $databaseName,
            $actor,
        ): TenantDatabase {
            if (TenantDatabase::query()->where('health_unit_id', $unit->getKey())->lockForUpdate()->exists()) {
                throw new LogicException('A unidade já possui um banco piloto registrado.');
            }

            $tenantDatabase = TenantDatabase::query()->create([
                'health_unit_id' => $unit->getKey(),
                'connection_profile' => trim($connectionProfile),
                'database_name' => filled($databaseName) ? trim((string) $databaseName) : null,
                'provisioning_mode' => 'legacy_migration',
                'state' => TenantDatabaseState::Legacy,
                'schema_status' => 'pending',
                'infrastructure_status' => 'external',
                'requested_by_user_id' => $actor->getKey(),
            ]);
            $this->event($tenantDatabase, $actor, 'registered', null, TenantDatabaseState::Legacy, [
                'connection_profile' => $tenantDatabase->connection_profile,
                'database_name' => $tenantDatabase->database_name,
            ]);
            $this->connections->forget($tenantDatabase);

            return $tenantDatabase->refresh();
        });
    }

    public function registerNative(HealthUnit $unit, User $actor): TenantDatabase
    {
        $this->authorize($unit, $actor);
        $databaseName = TenantDatabaseNaming::databaseName((string) $unit->public_id);
        $runtimeUsername = TenantDatabaseNaming::runtimeUsername((string) $unit->public_id);
        TenantDatabaseNaming::assertValidDatabaseName($databaseName);
        TenantDatabaseNaming::assertValidUsername($runtimeUsername);
        $runtimeHost = trim((string) config('tenancy.native_provisioning.runtime_host'));
        if ($runtimeHost === '' || $runtimeHost === '%') {
            throw new LogicException('O host da credencial de runtime deve ser explícito e restrito.');
        }
        $encryptedPassword = Crypt::encryptString(Str::password(48));

        return DB::connection('core')->transaction(function () use (
            $unit,
            $actor,
            $databaseName,
            $runtimeUsername,
            $runtimeHost,
            $encryptedPassword,
        ): TenantDatabase {
            if (TenantDatabase::query()->where('health_unit_id', $unit->getKey())->lockForUpdate()->exists()) {
                throw new LogicException('A unidade já possui um banco dedicado registrado.');
            }

            $tenantDatabase = TenantDatabase::query()->create([
                'health_unit_id' => $unit->getKey(),
                'connection_profile' => 'native',
                'database_name' => $databaseName,
                'provisioning_mode' => 'native',
                'state' => TenantDatabaseState::Legacy,
                'schema_status' => 'pending',
                'infrastructure_status' => 'credentials_staged',
                'runtime_username' => $runtimeUsername,
                'encrypted_runtime_password' => $encryptedPassword,
                'runtime_host' => $runtimeHost,
                'requested_by_user_id' => $actor->getKey(),
            ]);
            $this->event($tenantDatabase, $actor, 'native_provisioning_registered', null, TenantDatabaseState::Legacy, [
                'database_name' => $databaseName,
                'runtime_username' => $runtimeUsername,
                'runtime_host' => $runtimeHost,
                'infrastructure_status' => 'credentials_staged',
            ]);
            $this->connections->forget($tenantDatabase);

            return $tenantDatabase->refresh();
        });
    }

    /** @param array<string, mixed> $context */
    public function markInfrastructureStatus(
        TenantDatabase $tenantDatabase,
        User $actor,
        string $status,
        array $context = [],
    ): TenantDatabase {
        $this->authorize($tenantDatabase->healthUnit()->firstOrFail(), $actor);
        $allowed = ['credentials_staged', 'database_created', 'user_configured', 'grants_applied', 'failed'];
        if (! in_array($status, $allowed, true)) {
            throw new LogicException('Checkpoint de infraestrutura inválido.');
        }

        return DB::connection('core')->transaction(function () use ($tenantDatabase, $actor, $status, $context): TenantDatabase {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $record->update(['infrastructure_status' => $status]);
            $this->event($record, $actor, 'infrastructure_'.$status, $record->stateEnum(), $record->stateEnum(), $context);
            $this->connections->forget($record);

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $context */
    public function markProvisioningResult(
        TenantDatabase $tenantDatabase,
        User $actor,
        bool $succeeded,
        array $context = [],
    ): TenantDatabase {
        $this->authorize($tenantDatabase->healthUnit()->firstOrFail(), $actor);

        return DB::connection('core')->transaction(function () use (
            $tenantDatabase,
            $actor,
            $succeeded,
            $context,
        ): TenantDatabase {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $state = $record->stateEnum();
            if ($state !== TenantDatabaseState::Legacy) {
                throw new LogicException('O schema piloto só pode ser provisionado enquanto a unidade está em LEGACY.');
            }
            $record->update([
                'schema_status' => $succeeded ? 'ready' : 'failed',
                'provisioned_at' => $succeeded ? now() : null,
            ]);
            $this->event(
                $record,
                $actor,
                $succeeded ? 'schema_provisioned' : 'schema_provision_failed',
                $state,
                $state,
                $context,
            );
            $this->connections->forget($record);

            return $record->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function recordReconciliation(
        TenantDatabase $tenantDatabase,
        User $actor,
        string $status,
        array $summary,
    ): TenantDatabase {
        $this->authorize($tenantDatabase->healthUnit()->firstOrFail(), $actor);
        if (! in_array($status, ['matched', 'diverged', 'failed'], true)) {
            throw new LogicException('Estado de reconciliação inválido.');
        }

        return DB::connection('core')->transaction(function () use (
            $tenantDatabase,
            $actor,
            $status,
            $summary,
        ): TenantDatabase {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $state = $record->stateEnum();
            if (! in_array($state, [TenantDatabaseState::Shadow, TenantDatabaseState::Validating], true)) {
                throw new LogicException('A reconciliação só pode ser registrada em SHADOW ou VALIDATING.');
            }
            $record->update([
                'last_reconciliation_status' => $status,
                'last_reconciliation_state' => $state->value,
                'last_reconciliation_summary' => $this->sanitizer->sanitize($summary),
                'last_reconciled_at' => now(),
            ]);
            $this->event(
                $record,
                $actor,
                'reconciliation_'.$status,
                $state,
                $state,
                ['summary' => $summary],
            );
            $this->connections->forget($record);

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $context */
    public function transition(
        TenantDatabase $tenantDatabase,
        TenantDatabaseState $target,
        User $actor,
        array $context = [],
    ): TenantDatabase {
        $this->authorize($tenantDatabase->healthUnit()->firstOrFail(), $actor);

        return DB::connection('core')->transaction(function () use (
            $tenantDatabase,
            $target,
            $actor,
            $context,
        ): TenantDatabase {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $from = $record->stateEnum();
            if (! $from->canTransitionTo($target)) {
                throw new LogicException("Transição de {$from->value} para {$target->value} não é permitida.");
            }
            if ($target === TenantDatabaseState::Shadow && $record->schema_status !== 'ready') {
                throw new LogicException('O schema dedicado precisa estar pronto antes de entrar em SHADOW.');
            }
            if ($target === TenantDatabaseState::Cutover
                && ($record->last_reconciliation_status !== 'matched'
                    || $record->last_reconciliation_state !== TenantDatabaseState::Validating->value
                    || ! $record->hasReconciliation())) {
                throw new LogicException('CUTOVER exige uma reconciliação VALIDATING concluída sem divergências.');
            }
            if ($target === TenantDatabaseState::Cutover
                && ($record->backup_verified_at === null || $record->restore_tested_at === null)) {
                throw new LogicException('CUTOVER exige evidência auditada de backup e teste de restauração.');
            }
            if ($target === TenantDatabaseState::Cutover && ! $record->reconciliationCoversContinuityEvidence()) {
                throw new LogicException('CUTOVER exige uma reconciliação final depois do teste de restauração.');
            }

            $timestamps = match ($target) {
                TenantDatabaseState::Cutover => ['cutover_at' => now()],
                TenantDatabaseState::Tenant => ['tenant_at' => now()],
                TenantDatabaseState::Rollback => ['rollback_at' => now()],
                default => [],
            };
            $record->update(['state' => $target, ...$timestamps]);
            if ($target === TenantDatabaseState::Tenant && $record->provisioning_mode === 'native') {
                $record->healthUnit()->update(['is_active' => true]);
            }
            $this->event($record, $actor, 'state_transitioned', $from, $target, $context);
            $this->connections->forget($record);

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $context */
    public function recordOperation(
        TenantDatabase $tenantDatabase,
        User $actor,
        string $action,
        array $context = [],
    ): void {
        $this->authorize($tenantDatabase->healthUnit()->firstOrFail(), $actor);
        $record = TenantDatabase::query()->findOrFail($tenantDatabase->getKey());
        $state = $record->stateEnum();
        $this->event($record, $actor, $action, $state, $state, $context);
    }

    /** @param array<string, mixed> $evidence */
    public function recordContinuityEvidence(
        TenantDatabase $tenantDatabase,
        User $actor,
        array $evidence,
    ): TenantDatabase {
        $this->authorize($tenantDatabase->healthUnit()->firstOrFail(), $actor);
        if (blank($evidence['backup_reference'] ?? null) || blank($evidence['restore_reference'] ?? null)) {
            throw new LogicException('Informe referências verificáveis de backup e restauração.');
        }
        if ($tenantDatabase->provisioning_mode === 'native') {
            $verification = BackupVerification::query()
                ->where('tenant_database_id', $tenantDatabase->getKey())
                ->where('public_id', (string) $evidence['backup_reference'])
                ->where('status', 'completed')
                ->where('restore_compatible', true)
                ->first();
            if ($verification === null) {
                throw new LogicException(
                    'Unidade nativa exige uma verificação de backup compatível vinculada ao próprio Tenant.',
                );
            }
            $evidence['backup_set'] = $verification->backup_set;
            $evidence['backup_verified_at'] = $verification->finishedAt()?->toIso8601String();
        }

        return DB::connection('core')->transaction(function () use ($tenantDatabase, $actor, $evidence): TenantDatabase {
            $record = TenantDatabase::query()->lockForUpdate()->findOrFail($tenantDatabase->getKey());
            $state = $record->stateEnum();
            if ($state !== TenantDatabaseState::Validating) {
                throw new LogicException('Evidências de continuidade só podem ser aceitas em VALIDATING.');
            }
            $record->update([
                'backup_verified_at' => now(),
                'restore_tested_at' => now(),
                'continuity_evidence' => $this->sanitizer->sanitize($evidence),
            ]);
            $this->event(
                $record,
                $actor,
                'continuity_evidence_recorded',
                $state,
                $state,
                $evidence,
            );

            return $record->refresh();
        });
    }

    /** @param array<string, mixed> $context */
    private function event(
        TenantDatabase $tenantDatabase,
        User $actor,
        string $action,
        ?TenantDatabaseState $from,
        ?TenantDatabaseState $to,
        array $context,
    ): void {
        TenantDatabaseEvent::query()->create([
            'tenant_database_id' => $tenantDatabase->getKey(),
            'actor_user_id' => $actor->getKey(),
            'action' => $action,
            'from_state' => $from?->value,
            'to_state' => $to?->value,
            'context' => $this->sanitizer->sanitize($context),
            'occurred_at' => now(),
        ]);
    }

    private function authorize(HealthUnit $unit, User $actor): void
    {
        if ($actor->isPlatformAdministrator()) {
            return;
        }
        if ((int) $actor->organization_id === (int) $unit->organization_id
            && $actor->can('administration.manage')) {
            return;
        }

        throw new LogicException('O responsável não pode administrar o banco desta unidade.');
    }
}
