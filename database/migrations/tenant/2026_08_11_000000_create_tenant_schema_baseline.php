<?php

declare(strict_types=1);

use App\Support\Tenancy\TenantSchemaHardener;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_MIGRATION_CUTOFF = '2026_08_10_090000_create_phase_five_operational_foundation.php';

    public function up(): void
    {
        $connection = DB::connection()->getName();
        if ($connection === 'core') {
            throw new RuntimeException('A baseline Tenant não pode ser executada na conexão Core.');
        }

        $hardener = app(TenantSchemaHardener::class);
        if (! Schema::hasTable('encounters')) {
            foreach ($this->legacyMigrationFiles() as $file) {
                $migration = require $file;
                if (! $migration instanceof Migration || ! is_callable([$migration, 'up'])) {
                    throw new RuntimeException('Arquivo histórico não retornou uma migration válida: '.basename($file));
                }
                call_user_func([$migration, 'up']);
            }
        }

        $hardener->harden($connection);
        $hardener->removeEmptyCoreCopies($connection);
    }

    public function down(): void
    {
        $connection = DB::connection()->getName();
        if ($connection === 'core') {
            throw new RuntimeException('A baseline Tenant não pode ser revertida na conexão Core.');
        }

        app(TenantSchemaHardener::class)->dropTenantTables($connection);
    }

    /** @return list<string> */
    private function legacyMigrationFiles(): array
    {
        $files = glob(database_path('migrations/*.php'));
        if ($files === false) {
            throw new RuntimeException('Não foi possível localizar as migrations históricas.');
        }

        $files = array_values(array_filter(
            $files,
            static fn (string $file): bool => basename($file) <= self::LEGACY_MIGRATION_CUTOFF,
        ));
        sort($files);

        return $files;
    }
};
