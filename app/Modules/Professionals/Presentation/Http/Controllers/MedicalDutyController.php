<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Actions\ManageMedicalDutyAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MedicalDutyController extends Controller
{
    public function checkIn(Request $request, ManageMedicalDutyAction $action): RedirectResponse
    {
        [$user, $unit] = $this->context($request);
        $action->checkIn($user, $unit, $request);

        return back()->with('success', 'Check-in do plantão realizado nesta unidade.');
    }

    public function checkOut(Request $request, ManageMedicalDutyAction $action): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        [$user, $unit] = $this->context($request);
        $action->checkOut($user, $unit, (string) $data['reason'], $request);

        return back()->with('success', 'Plantão encerrado nesta unidade.');
    }

    /** @return array{User, HealthUnit} */
    private function context(Request $request): array
    {
        $user = $request->user();
        $unit = $request->attributes->get('active_health_unit');
        abort_unless($user instanceof User && $unit instanceof HealthUnit, 403);

        return [$user, $unit];
    }
}
