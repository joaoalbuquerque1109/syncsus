<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class ToggleHealthUnitActiveAction
{
    public function __construct(private RecordAuditEventAction $recordAuditEvent) {}

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

        return $unit->refresh();
    }
}
