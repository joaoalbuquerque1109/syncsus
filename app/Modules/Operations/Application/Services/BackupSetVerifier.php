<?php

declare(strict_types=1);

namespace App\Modules\Operations\Application\Services;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Operations\Infrastructure\Eloquent\BackupVerification;
use RuntimeException;
use Throwable;

final class BackupSetVerifier
{
    public function verify(string $backupSet, ?User $actor = null): BackupVerification
    {
        $root = realpath((string) config('sync_sus.backup_path'));
        $path = realpath($backupSet);
        if ($root === false || $path === false || ! is_dir($path) || ! $this->isWithin($path, $root)) {
            throw new RuntimeException('Conjunto fora do diretório de backups autorizado.');
        }

        $verification = BackupVerification::query()->create([
            'backup_set' => basename($path),
            'status' => 'running',
            'verified_by' => $actor?->getKey(),
            'started_at' => now(),
        ]);

        try {
            $checks = $this->runChecks($path);
            $verification->update([
                'status' => 'completed',
                'checks' => $checks,
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
