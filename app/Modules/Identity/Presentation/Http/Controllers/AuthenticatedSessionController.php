<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Application\Actions\AuthenticateUserAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request, AuthenticateUserAction $authenticate): RedirectResponse
    {
        $user = $authenticate->execute($request);
        $destination = $user->must_change_password && ! $user->isPlatformAdministrator()
            ? 'password.edit'
            : 'dashboard';

        return redirect()->intended(route($destination, absolute: false));
    }

    public function destroy(Request $request, RecordAuditEventAction $recordAuditEvent): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $recordAuditEvent->execute(
                action: 'user.logged_out',
                request: $request,
                user: $user,
                healthUnitId: $request->session()->get('active_health_unit_id'),
            );
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
