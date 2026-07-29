<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnforceHttps
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('sync_sus.require_https') || $request->isSecure()) {
            return $next($request);
        }

        if ($request->isMethodSafe()) {
            $target = 'https://'.$request->getHttpHost().$request->getRequestUri();

            return redirect()->to($target, 308);
        }

        abort(400, 'HTTPS é obrigatório para esta operação.');
    }
}
