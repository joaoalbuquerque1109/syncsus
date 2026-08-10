<?php

declare(strict_types=1);

namespace App\Modules\Administration\Application\Jobs;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Support\Tenancy\TenantInfrastructureProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

final class ProvisionTenantDatabaseJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 3600;

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

    public function handle(TenantInfrastructureProvisioner $provisioner): void
    {
        $database = TenantDatabase::query()
            ->whereHas('healthUnit', fn ($query) => $query->where('public_id', $this->healthUnitPublicId))
            ->firstOrFail();

        if ($database->infrastructure_status === 'grants_applied') {
            return;
        }

        $provisioner->provision($database);
    }
}
