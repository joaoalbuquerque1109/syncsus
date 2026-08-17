<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\TenantDatabase;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Operations\Infrastructure\Eloquent\BackupVerification;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class BackupSetVerifier
{
    public function verify(
        string $backupSet,
        ?User $actor = null,
        ?TenantDatabase $tenantDatabase = null,
    ): BackupVerification {
        $root = realpath((string) config('sync_sus.backup_path'));
        $path = realpath($backupSet);
        if ($root === false || $path === false || ! is_dir($path) || ! $this->isWithin($path, $root)) {
            throw new RuntimeException('Conjunto fora do diretório de backups autorizado.');
        }

        $verification = BackupVerification::query()->create([
            'tenant_database_id' => $tenantDatabase?->getKey(),
            'health_unit_id' => $tenantDatabase?->health_unit_id,
            'backup_scope' => $tenantDatabase === null ? 'core' : 'tenant',
            'backup_set' => basename($path),
            'status' => 'running',
            'verified_by' => $actor?->getKey(),
            'started_at' => now(),
        ]);

        try {
            $checks = $this->runChecks($path);
            $continuity = $tenantDatabase === null
                ? ['core_reference_at' => null, 'restore_point_at' => null, 'restore_compatible' => null]
                : $this->verifyTenantMetadata($path, $tenantDatabase);
            $verification->update([
                'status' => 'completed',
                'checks' => [...$checks, ...$continuity],
                ...$continuity,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $verification->update([
                'status' => 'failed',
                'error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'finished_at' => now(),
            ]);

            throw $exception;
        }

        return $verification->fresh() ?? $verification;
    }

    /** @return array{manifest: bool, hashes: bool, database_archive: string, files_archive: string, encrypted: bool} */
    private function runChecks(string $path): array
    {
        $manifestPath = $path.DIRECTORY_SEPARATOR.'SHA256SUMS';
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('Manifesto SHA256SUMS ausente ou ilegível.');
        }
        $lines = file($manifestPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || count($lines) !== 2) {
            throw new RuntimeException('Manifesto deve conter exatamente os arquivos do banco e do storage privado.');
        }

        $files = [];
        foreach ($lines as $line) {
            if (preg_match('/\A([a-f0-9]{64})\s+\*?([A-Za-z0-9._-]+)\z/', trim($line), $matches) !== 1) {
                throw new RuntimeException('Manifesto contém uma entrada inválida.');
            }
            $filePath = $path.DIRECTORY_SEPARATOR.$matches[2];
            if (! is_file($filePath) || ! hash_equals($matches[1], hash_file('sha256', $filePath))) {
                throw new RuntimeException('Falha de integridade em um artefato do backup.');
            }
            $files[] = $matches[2];
        }

        $database = collect($files)->first(fn (string $file): bool => str_starts_with($file, 'database.sql.gz'));
        $privateFiles = collect($files)->first(fn (string $file): bool => str_starts_with($file, 'private-files.tar.gz'));
        if (! is_string($database) || ! is_string($privateFiles)) {
            throw new RuntimeException('Arquivos obrigatórios do backup não foram encontrados.');
        }
        $encrypted = str_ends_with($database, '.enc') && str_ends_with($privateFiles, '.enc');
        if (! $encrypted) {
            $this->assertValidGzip($path.DIRECTORY_SEPARATOR.$database);
            $this->assertValidGzip($path.DIRECTORY_SEPARATOR.$privateFiles);
        }

        return [
            'manifest' => true,
            'hashes' => true,
            'database_archive' => $database,
            'files_archive' => $privateFiles,
            'encrypted' => $encrypted,
        ];
    }

    /** @return array{core_reference_at: string, restore_point_at: string, restore_compatible: true} */
    private function verifyTenantMetadata(string $path, TenantDatabase $tenantDatabase): array
    {
        $metadataPath = $path.DIRECTORY_SEPARATOR.'TENANT_BACKUP.json';
        if (! is_file($metadataPath) || ! is_readable($metadataPath)) {
            throw new RuntimeException('Metadados TENANT_BACKUP.json ausentes para o backup da unidade.');
        }
        $contents = file_get_contents($metadataPath);
        $metadata = is_string($contents) ? json_decode($contents, true) : null;
        if (! is_array($metadata)) {
            throw new RuntimeException('Metadados do backup da unidade são inválidos.');
        }
        $unit = $tenantDatabase->healthUnit()->firstOrFail();
        if (($metadata['tenant_database_public_id'] ?? null) !== $tenantDatabase->public_id
            || ($metadata['health_unit_public_id'] ?? null) !== $unit->public_id) {
            throw new RuntimeException('O conjunto de backup pertence a outra unidade ou banco Tenant.');
        }
        $coreReferenceValue = $metadata['core_reference_at'] ?? null;
        $restorePointValue = $metadata['restore_point_at'] ?? null;
        if (! is_string($coreReferenceValue) || trim($coreReferenceValue) === ''
            || ! is_string($restorePointValue) || trim($restorePointValue) === '') {
            throw new RuntimeException('As referências temporais do backup da unidade são inválidas.');
        }
        try {
            $coreReference = Carbon::parse($coreReferenceValue);
            $restorePoint = Carbon::parse($restorePointValue);
        } catch (Throwable) {
            throw new RuntimeException('As referências temporais do backup da unidade são inválidas.');
        }
        if ($restorePoint->lt($coreReference)) {
            throw new RuntimeException('O ponto de restore é anterior à referência Core declarada.');
        }

        return [
            'core_reference_at' => $coreReference->toIso8601String(),
            'restore_point_at' => $restorePoint->toIso8601String(),
            'restore_compatible' => true,
        ];
    }

    private function assertValidGzip(string $path): void
    {
        $stream = gzopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Arquivo compactado inválido.');
        }
        while (! gzeof($stream)) {
            if (gzread($stream, 1024 * 1024) === false) {
                gzclose($stream);
                throw new RuntimeException('Falha ao ler arquivo compactado.');
            }
        }
        gzclose($stream);
    }

    private function isWithin(string $path, string $root): bool
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/').'/';
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/').'/';

        return str_starts_with($normalizedPath, $normalizedRoot);
    }
}
