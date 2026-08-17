<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MigrateTenantSecurityAuditHistory
{
    /** @var list<string> */
    private const SECURITY_PREFIXES = ['user.', 'professional.', 'organization.', 'tenant.', 'security.'];

    /**
     * Copia idempotentemente eventos de segurança do audit_logs de uma conexão de unidade
     * para security_audit_logs (Core), usando public_id como chave de deduplicação.
     *
     * @return array{found: int, already_present: int, migrated: int}
     */
    public function execute(string $sourceConnection, bool $apply, bool $deleteAfterCopy): array
    {
        $source = DB::connection($sourceConnection);
        $core = DB::connection('core');
        $result = ['found' => 0, 'already_present' => 0, 'migrated' => 0];

        $source->table('audit_logs')
            ->where(function ($query): void {
                foreach (self::SECURITY_PREFIXES as $index => $prefix) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}('action', 'like', $prefix.'%');
                }
            })
            ->orderBy('id')
            ->chunkById(500, function ($logs) use ($source, $core, $apply, $deleteAfterCopy, &$result): void {
                foreach ($logs as $log) {
                    $result['found']++;
                    $alreadyPresent = $core->table('security_audit_logs')
                        ->where('public_id', $log->public_id)
                        ->exists();

                    if ($alreadyPresent) {
                        $result['already_present']++;
                    } elseif ($apply) {
                        $attributes = (array) $log;
                        unset($attributes['id'], $attributes['patient_id'], $attributes['encounter_id']);
                        // Snapshots anteriores à criação da coluna correlation_id não a possuem.
                        $attributes['correlation_id'] ??= (string) Str::ulid();
                        $core->table('security_audit_logs')->insert($attributes);
                        $result['migrated']++;
                    } else {
                        $result['migrated']++;
                    }

                    if ($apply && $deleteAfterCopy) {
                        $source->table('audit_logs')->where('id', $log->id)->delete();
                    }
                }
            });

        return $result;
    }
}
