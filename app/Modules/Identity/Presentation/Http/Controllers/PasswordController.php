<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Application\Actions\ChangePasswordAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Presentation\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PasswordController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('auth.change-password', [
            'isRequired' => $user?->must_change_password === true
                && ! $user->isPlatformAdministrator(),
        ]);
    }

    public function update(ChangePasswordRequest $request, ChangePasswordAction $changePassword): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $changePassword->execute($user, (string) $request->string('password'), $request);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Senha atualizada com segurança.');
    }
}
