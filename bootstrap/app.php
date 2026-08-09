<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticateSynclabResultWebhook;
use App\Http\Middleware\EnforceHttps;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Identity\Presentation\Http\Middleware\EnsureActiveHealthUnit;
use App\Modules\Identity\Presentation\Http\Middleware\EnsurePasswordWasChanged;
use App\Modules\Identity\Presentation\Http\Middleware\EnsureUserIsActive;
use App\Support\DeploymentNetworkConfiguration;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $railwayServiceId = (string) env('RAILWAY_SERVICE_ID', '');
        $railwayPublicDomain = (string) env('RAILWAY_PUBLIC_DOMAIN', '');
        $middleware->trustHosts(
            at: DeploymentNetworkConfiguration::trustedHostPatterns(
                (string) env('APP_URL', 'http://localhost'),
                (string) env('SYNC_SUS_TRUSTED_HOSTS', 'localhost,127.0.0.1'),
                $railwayServiceId,
                $railwayPublicDomain,
            ),
            subdomains: false,
        );
        $trustedProxies = DeploymentNetworkConfiguration::trustedProxies(
            (string) env('SYNC_SUS_TRUSTED_PROXIES', ''),
            $railwayServiceId,
            $railwayPublicDomain,
        );
        if ($trustedProxies !== null) {
            $middleware->trustProxies(at: $trustedProxies);
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
            'synclab.results' => AuthenticateSynclabResultWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
