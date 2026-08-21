<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class SeedOnce extends Command
{
    protected $signature = 'sync-sus:seed-once';

    protected $description = 'Executa db:seed protegido por lock distribuido, evitando execucao concorrente entre replicas no boot';

    public function handle(): int
    {
        $ttlSeconds = (int) config('sync_sus.startup_seed_lock.ttl_seconds');
        $blockSeconds = (int) config('sync_sus.startup_seed_lock.block_seconds');
        $lock = Cache::lock('sync-sus:startup-db-seed', $ttlSeconds);

        try {
            return $lock->block($blockSeconds, fn (): int => $this->call('db:seed', [
                '--force' => true,
                '--no-interaction' => true,
            ]));
        } catch (LockTimeoutException) {
            $this->components->error("Nao foi possivel obter o lock de seed apos {$blockSeconds}s; outra replica pode estar presa no db:seed.");

            return self::FAILURE;
        }
    }
}
