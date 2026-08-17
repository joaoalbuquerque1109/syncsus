<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabaseEvent;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

final readonly class TenantDatabaseAutoProvisioner
{
    public function __construct(
        private TenantInfrastructureProvisioner $infrastructure,
        private TenantDatabaseProvisioner $schema,
        private TenantPilotDataSynchronizer $synchronizer,
        private TenantDatabaseReconciler $reconciler,
        private TenantDatabaseLifecycle $lifecycle,
    ) {}

    /** @return array{status: string, state: string, next_step: string} */
    public function advance(TenantDatabase $tenantDatabase): array
    {
        if ($tenantDatabase->provisioning_mode !== 'native') {
            throw new LogicException('A orquestração automática é exclusiva de unidades provisionadas nativamente.');
        }
        $actor = $tenantDatabase->requestedBy()->firstOrFail();
        Log::info('tenant.auto_provisioning_started', $this->logContext($tenantDatabase));

        try {
            for ($step = 0; $step < 8; $step++) {
                $tenantDatabase = $tenantDatabase->refresh();
                switch ($tenantDatabase->stateEnum()) {
                    case TenantDatabaseState::Legacy:
                        if ($tenantDatabase->infrastructure_status !== 'grants_applied') {
                            $tenantDatabase = $this->infrastructure->provision($tenantDatabase);
                        }
                        $tenantDatabase = $this->schema->provision($tenantDatabase->refresh(), $actor);
                        break;

                    case TenantDatabaseState::Shadow:
                        $this->synchronizer->synchronize($tenantDatabase, $actor);
                        $reconciliation = $this->reconciler->reconcile($tenantDatabase->refresh(), $actor);
                        if ($reconciliation['status'] !== 'matched') {
                            return $this->pause($tenantDatabase, $actor, 'diverged', 'reconcile_shadow');
                        }
                        $tenantDatabase = $this->lifecycle->transition(
                            $tenantDatabase->refresh(),
                            TenantDatabaseState::Validating,
                            $actor,
                            ['source' => 'native_auto_provisioning'],
                        );
                        break;

                    case TenantDatabaseState::Validating:
                        if ($tenantDatabase->backup_verified_at === null || $tenantDatabase->restore_tested_at === null) {
                            return $this->pause($tenantDatabase, $actor, 'waiting_continuity', 'record_continuity');
                        }
                        $reconciliation = $this->reconciler->reconcile($tenantDatabase, $actor);
                        if ($reconciliation['status'] !== 'matched') {
                            return $this->pause($tenantDatabase, $actor, 'diverged', 'reconcile_validating');
                        }
                        if (! config('tenancy.native_provisioning.automatic_cutover_enabled', false)) {
                            return $this->pause($tenantDatabase, $actor, 'waiting_cutover_approval', 'enable_cutover');
                        }
                        $tenantDatabase = $this->lifecycle->transition(
                            $tenantDatabase->refresh(),
                            TenantDatabaseState::Cutover,
                            $actor,
                            ['source' => 'native_auto_provisioning'],
                        );
                        break;

                    case TenantDatabaseState::Cutover:
                        $tenantDatabase = $this->lifecycle->transition(
                            $tenantDatabase,
                            TenantDatabaseState::Tenant,
                            $actor,
                            ['source' => 'native_auto_provisioning'],
                        );
                        break;

                    case TenantDatabaseState::Tenant:
                        Log::info('tenant.auto_provisioning_completed', $this->logContext($tenantDatabase));

                        return ['status' => 'complete', 'state' => 'TENANT', 'next_step' => 'none'];

                    case TenantDatabaseState::Rollback:
                        return $this->pause($tenantDatabase, $actor, 'rollback', 'manual_recovery');
                }
            }
        } catch (Throwable $exception) {
            try {
                $this->lifecycle->recordOperation(
                    $tenantDatabase,
                    $actor,
                    'auto_provisioning_failed',
                    ['error_type' => $exception::class],
                );
            } catch (Throwable) {
                // Preserve the provisioning failure that the queue must retry or expose.
            }
            Log::error('tenant.auto_provisioning_failed', [
                ...$this->logContext($tenantDatabase),
                'exception' => $exception::class,
            ]);
            throw $exception;
        }

        throw new LogicException('O provisionamento excedeu o limite de transições convergentes.');
    }

    public function nextStep(TenantDatabase $tenantDatabase): string
    {
        return match ($tenantDatabase->stateEnum()) {
            TenantDatabaseState::Legacy => $tenantDatabase->infrastructure_status === 'grants_applied'
                ? 'schema_tenant'
                : 'infrastructure',
            TenantDatabaseState::Shadow => 'synchronize_and_reconcile',
            TenantDatabaseState::Validating => $tenantDatabase->backup_verified_at === null
                || $tenantDatabase->restore_tested_at === null
                ? 'record_continuity'
                : (config('tenancy.native_provisioning.automatic_cutover_enabled', false)
                    ? 'final_reconciliation_and_cutover'
                    : 'enable_cutover'),
            TenantDatabaseState::Cutover => 'activate_tenant',
            TenantDatabaseState::Tenant => 'none',
            TenantDatabaseState::Rollback => 'manual_recovery',
        };
    }

    /** @return array{status: string, state: string, next_step: string} */
    private function pause(
        TenantDatabase $tenantDatabase,
        User $actor,
        string $status,
        string $nextStep,
    ): array {
        $lastAction = TenantDatabaseEvent::query()
            ->where('tenant_database_id', $tenantDatabase->getKey())
            ->latest('occurred_at')
            ->value('action');
        $action = 'auto_provisioning_'.$status;
        if ($lastAction !== $action) {
            $this->lifecycle->recordOperation($tenantDatabase, $actor, $action, ['next_step' => $nextStep]);
        }
        Log::info('tenant.auto_provisioning_paused', [
            ...$this->logContext($tenantDatabase),
            'status' => $status,
            'next_step' => $nextStep,
        ]);

        return [
            'status' => $status,
            'state' => $tenantDatabase->stateEnum()->value,
            'next_step' => $nextStep,
        ];
    }

    /** @return array{health_unit_public_id: string, tenant_database_public_id: string, state: string} */
    private function logContext(TenantDatabase $tenantDatabase): array
    {
        return [
            'health_unit_public_id' => (string) $tenantDatabase->healthUnit()->value('public_id'),
            'tenant_database_public_id' => (string) $tenantDatabase->public_id,
            'state' => $tenantDatabase->stateEnum()->value,
        ];
    }
}
