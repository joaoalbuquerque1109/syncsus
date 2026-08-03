<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Documents\Application\Contracts\PdfRenderer;
use App\Modules\Documents\Infrastructure\Pdf\DompdfRenderer;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Contracts\LaboratoryProviderClient;
use App\Modules\Laboratory\Infrastructure\Synclab\SynclabClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PdfRenderer::class, DompdfRenderer::class);
        $this->app->bind(LaboratoryProviderClient::class, SynclabClient::class);
    }

    public function boot(): void
    {
        Gate::before(
            static fn (User $user): ?bool => $user->isPlatformAdministrator() ? true : null,
        );

        Password::defaults(
            fn () => Password::min(12)->mixedCase()->numbers()->symbols(),
        );
        RateLimiter::for('public-panels', fn (Request $request): Limit => Limit::perMinute(180)->by($request->ip()));
        RateLimiter::for('document-verification', fn (Request $request): Limit => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perMinute(20)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
