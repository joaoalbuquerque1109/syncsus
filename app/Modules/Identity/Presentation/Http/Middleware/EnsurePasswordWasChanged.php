<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePasswordWasChanged
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->must_change_password === true && ! $user->isPlatformAdministrator()) {
            return redirect()
                ->route('password.edit')
                ->with('warning', 'Defina uma nova senha antes de continuar.');
        }

        return $next($request);
    }
}
