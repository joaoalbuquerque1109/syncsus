<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final readonly class SwitchActiveHealthUnitAction
{
    public function __construct(private RecordAuditEventAction $recordAuditEvent) {}

    public function execute(User $user, string $publicId, Request $request): HealthUnit
    {
        $cnes = preg_replace('/\D/', '', $publicId);
        $unit = $user->isPlatformAdministrator()
            ? HealthUnit::query()
                ->where(function ($query) use ($publicId, $cnes): void {
                    $query->where('public_id', $publicId);
                    if ($cnes !== '' && strlen($cnes) === 7) {
                        $query->orWhere('cnes_code', $cnes)
                            ->orWhereHas('organization', fn ($organization) => $organization->where('cnes_code', $cnes));
                    }
                })
                ->where('is_active', true)
                ->whereHas('organization', fn ($query) => $query->where('is_active', true))
                ->first()
            : $user->healthUnits()
                ->where('health_units.public_id', $publicId)
                ->where('health_units.is_active', true)
                ->whereHas('organization', fn ($query) => $query->where('is_active', true))
                ->first();

        if ($unit === null) {
            throw new AuthorizationException('Você não possui acesso à unidade selecionada.');
        }

        $request->session()->put('active_health_unit_id', $unit->getKey());
        $this->recordAuditEvent->execute(
            action: 'user.active_health_unit_changed',
            request: $request,
            user: $user,
            context: ['health_unit_public_id' => $unit->public_id],
            healthUnitId: (int) $unit->getKey(),
        );

        return $unit;
    }
}
