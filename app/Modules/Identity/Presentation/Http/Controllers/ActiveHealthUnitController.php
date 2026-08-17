<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Actions\SwitchActiveHealthUnitAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Presentation\Http\Requests\SwitchHealthUnitRequest;
use Illuminate\Http\RedirectResponse;

final class ActiveHealthUnitController extends Controller
{
    public function update(
        SwitchHealthUnitRequest $request,
        SwitchActiveHealthUnitAction $switchActiveHealthUnit,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $unit = $switchActiveHealthUnit->execute(
            $user,
            (string) $request->string('health_unit'),
            $request,
        );

        $redirect = $user->isPlatformAdministrator()
            ? redirect()->route('dashboard')
            : back();

        return $redirect->with('success', "Unidade ativa alterada para {$unit->name}.");
    }
}
