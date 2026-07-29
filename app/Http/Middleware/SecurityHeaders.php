<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        [$viteOrigins, $viteWebSocketOrigins] = $this->localViteSources();
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set(
            'Content-Security-Policy',
            implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "frame-ancestors 'none'",
                "object-src 'none'",
                "img-src 'self' data:",
                "font-src 'self' data:",
                $this->directive('style-src', ["'self'", "'unsafe-inline'", ...$viteOrigins]),
                $this->directive('script-src', ["'self'", "'unsafe-eval'", ...$viteOrigins]),
                $this->directive('connect-src', ["'self'", ...$viteOrigins, ...$viteWebSocketOrigins]),
                "form-action 'self'",
            ]).';',
        );
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if ($request->user() !== null && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }

    /**
     * @return array{list<string>, list<string>}
     */
    private function localViteSources(): array
    {
        if (! app()->isLocal()) {
            return [[], []];
        }

        $configuredOrigins = config('sync_sus.vite_dev_server_origins', []);
        if (! is_array($configuredOrigins)) {
            return [[], []];
        }

        $origins = [];
        foreach ($configuredOrigins as $configuredOrigin) {
            if (! is_string($configuredOrigin)) {
                continue;
            }
            $origin = rtrim(trim($configuredOrigin), '/');
            if (
                preg_match('#^https?://(?:localhost|127\.0\.0\.1|\[::1\])(?::\d{1,5})?$#', $origin) === 1
                && ! in_array($origin, $origins, true)
            ) {
                $origins[] = $origin;
            }
        }
        $webSockets = array_values(array_filter(array_map(
            fn (string $origin): string => preg_replace('#^http#', 'ws', $origin) ?? '',
            $origins,
        )));

        return [$origins, $webSockets];
    }

    /** @param list<string> $sources */
    private function directive(string $name, array $sources): string
    {
        return $name.' '.implode(' ', $sources);
    }
}
