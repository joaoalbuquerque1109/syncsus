<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Documents\Application\Contracts\PdfRenderer;
use App\Modules\Documents\Infrastructure\Pdf\DompdfRenderer;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Contracts\LaboratoryProviderClient;
use App\Modules\Laboratory\Infrastructure\Synclab\SynclabClient;
use App\Support\Models\TenantModel;
use App\Support\Tenancy\ActiveHealthUnitTenantResolver;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantResolver;
use App\Support\Tenancy\TenantShadowWriter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(TenantConnectionManager::class);
        $this->app->bind(TenantResolver::class, ActiveHealthUnitTenantResolver::class);
    }

    public function boot(): void
    {
        Event::listen('eloquent.saved: *', function (string $event, array $models): void {
            $model = $models[0] ?? null;
            if ($model instanceof TenantModel || $model instanceof AuditLog) {
                app(TenantShadowWriter::class)->mirrorSaved($model);
            }
        });
        Event::listen('eloquent.deleted: *', function (string $event, array $models): void {
            $model = $models[0] ?? null;
            if ($model instanceof TenantModel || $model instanceof AuditLog) {
                app(TenantShadowWriter::class)->mirrorDeleted($model);
            }
        });
        DB::listen(fn (QueryExecuted $query) => app(TenantShadowWriter::class)->mirrorPivotStatement($query));
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
