<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureUserIsActive
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_active) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->withErrors(['email' => 'Sua conta está inativa. Procure a administração.']);
    }
}
