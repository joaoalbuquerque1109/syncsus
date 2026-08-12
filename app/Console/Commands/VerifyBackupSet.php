<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Operations\Application\Services\BackupSetVerifier;
use Illuminate\Console\Command;
use Throwable;

final class VerifyBackupSet extends Command
{
    protected $signature = 'sync-sus:backup-verify
                            {path : Diretório do conjunto dentro de SYNC_SUS_BACKUP_PATH}
                            {--actor= : E-mail do administrador responsável}
                            {--unit= : Public ID da unidade quando o conjunto for Tenant}';

    protected $description = 'Valida manifesto, hashes e legibilidade dos arquivos de um conjunto de backup';

    public function handle(BackupSetVerifier $verifier): int
    {
        $actor = null;
        if (filled($this->option('actor'))) {
            $actor = User::query()->where('email', mb_strtolower((string) $this->option('actor')))->first();
            if (! $actor?->can('administration.manage')) {
                $this->components->error('O responsável informado não possui administração.manage.');

                return self::FAILURE;
            }
        }

        try {
            $tenantDatabase = null;
            if (filled($this->option('unit'))) {
                $unit = HealthUnit::query()
                    ->where('public_id', (string) $this->option('unit'))
                    ->firstOrFail();
                $tenantDatabase = TenantDatabase::query()
                    ->where('health_unit_id', $unit->getKey())
                    ->firstOrFail();
            }
            $result = $verifier->verify((string) $this->argument('path'), $actor, $tenantDatabase);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Backup {$result->backup_set} verificado com sucesso.");

        return self::SUCCESS;
    }
}
