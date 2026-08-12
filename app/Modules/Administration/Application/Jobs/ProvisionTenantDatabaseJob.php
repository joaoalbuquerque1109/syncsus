<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Jobs;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Support\Tenancy\TenantDatabaseAutoProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProvisionTenantDatabaseJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $healthUnitPublicId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 900];
    }

    public function uniqueId(): string
    {
        return $this->healthUnitPublicId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('tenant-provisioning:'.$this->healthUnitPublicId))->expireAfter(1800)];
    }

    public function handle(TenantDatabaseAutoProvisioner $provisioner): void
    {
        $database = TenantDatabase::query()
            ->whereHas('healthUnit', fn ($query) => $query->where('public_id', $this->healthUnitPublicId))
            ->firstOrFail();

        $provisioner->advance($database);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('tenant.auto_provisioning_job_failed', [
            'health_unit_public_id' => $this->healthUnitPublicId,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
