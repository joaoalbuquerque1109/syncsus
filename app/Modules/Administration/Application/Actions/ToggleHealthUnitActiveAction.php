<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Services\SynclabIntegrationReadiness;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class ToggleHealthUnitActiveAction
{
    public function __construct(
        private RecordAuditEventAction $recordAuditEvent,
        private SynclabIntegrationReadiness $synclabReadiness,
        private TenantContext $tenantContext,
        private TenantConnectionManager $connections,
    ) {}

    public function execute(HealthUnit $unit, User $actor, Request $request): HealthUnit
    {
        $wasActive = $unit->is_active;

        DB::connection('core')->transaction(function () use ($unit, $actor, $request, $wasActive): void {
            $unit->update(['is_active' => ! $wasActive]);
            $this->recordAuditEvent->execute(
                action: $wasActive ? 'tenant.health_unit_deactivated' : 'tenant.health_unit_activated',
                request: $request,
                user: $actor,
                context: [
                    'health_unit_public_id' => $unit->public_id,
                    'previous_is_active' => $wasActive,
                    'new_is_active' => ! $wasActive,
                ],
                healthUnitId: (int) $unit->getKey(),
            );
        });

        // Unidade reativada volta a ter a integracao Synclab pronta por
        // padrao (envio e recepcao), a mesma regra aplicada no provisionamento
        // inicial - so quando ainda intocada, nunca sobrescrevendo config real.
        if (! $wasActive) {
            $previousHealthUnit = $this->tenantContext->isResolved() ? $this->tenantContext->healthUnit() : null;
            $previousConnection = $this->tenantContext->isResolved() ? $this->tenantContext->connectionName() : null;
            $this->tenantContext->reset();
            $this->tenantContext->resolve($unit, $this->connections->connectionName($unit));
            try {
                $this->synclabReadiness->ensureReady($unit);
            } finally {
                $this->tenantContext->reset();
                if ($previousHealthUnit !== null && $previousConnection !== null) {
                    $this->tenantContext->resolve($previousHealthUnit, $previousConnection);
                }
            }
        }

        return $unit->refresh();
    }
}
