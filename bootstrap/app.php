<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Identity\Presentation\Http\Middleware\EnsureActiveHealthUnit;
use App\Modules\Identity\Presentation\Http\Middleware\EnsurePasswordWasChanged;
use App\Modules\Identity\Presentation\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SYNC_SUS_TRUSTED_HOSTS', 'localhost,127.0.0.1')),
        )));
        $railwayPublicDomain = trim((string) env('RAILWAY_PUBLIC_DOMAIN', ''));
        if ($railwayPublicDomain !== '') {
            $trustedHosts[] = $railwayPublicDomain;
        }
        $middleware->trustHosts(
            at: $trustedHosts,
            subdomains: false,
        );
        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SYNC_SUS_TRUSTED_PROXIES', '')),
        )));
        if ($trustedProxies !== []) {
            $proxyValue = count($trustedProxies) === 1 && in_array($trustedProxies[0], ['*', '**'], true)
                ? $trustedProxies[0]
                : $trustedProxies;
            $middleware->trustProxies(at: $proxyValue);
        }
        $middleware->append(EnforceHttps::class);
        $middleware->append(SecurityHeaders::class);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/dashboard');
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordWasChanged::class,
            'active.unit' => EnsureActiveHealthUnit::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
