<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class SeedOnceCommandTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_it_runs_db_seed_and_releases_the_lock_when_no_replica_is_seeding(): void
    {
        $exitCode = $this->artisan('sync-sus:seed-once')->run();

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(Cache::lock('sync-sus:startup-db-seed', 300)->get());
    }

    public function test_it_fails_without_running_db_seed_when_another_replica_already_holds_the_lock(): void
    {
        config(['sync_sus.startup_seed_lock.block_seconds' => 1]);
        $heldByOtherReplica = Cache::lock('sync-sus:startup-db-seed', 300);
        $this->assertTrue($heldByOtherReplica->get());

        $exitCode = $this->artisan('sync-sus:seed-once')->run();

        $this->assertSame(Command::FAILURE, $exitCode);
        $heldByOtherReplica->release();
    }
}
