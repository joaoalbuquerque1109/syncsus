<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

final class TenantSchemaHardener
{
    /** @var list<string> */
    private const CORE_TABLES = [
        'organizations',
        'health_units',
        'specialties',
        'risk_levels',
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'role_has_permissions',
        'health_unit_user',
        'health_professionals',
        'professional_registrations',
        'health_professional_specialty',
        'health_professional_health_unit',
        'patients',
        'patient_identifiers',
        'patient_operation_keys',
        'patient_unit_participations',
        'patient_unit_migration_conflicts',
        'core_number_sequences',
        'diagnosis_codes',
        'sus_procedures',
        'exams',
        'exam_groups',
        'exam_group_items',
        'public_lookup_index',
        'tenant_databases',
        'tenant_database_events',
        'backup_logs',
        'backup_verifications',
        'cache',
        'cache_locks',
        'failed_jobs',
        'job_batches',
        'jobs',
        'migrations',
        'password_reset_tokens',
        'sessions',
    ];

    /** @return array<string, int> */
    public function harden(string $connectionName): array
    {
        $schema = Schema::connection($connectionName);
        $removed = [];

        foreach ($schema->getTables() as $tableDefinition) {
            $table = $tableDefinition['name'];
            if ($table === '' || in_array($table, self::CORE_TABLES, true)) {
                continue;
            }

            foreach ($schema->getForeignKeys($table) as $foreignKey) {
                if (! in_array($foreignKey['foreign_table'], self::CORE_TABLES, true)) {
                    continue;
                }

                $identifier = $foreignKey['name'] ?? $foreignKey['columns'];
                $schema->table($table, function (Blueprint $blueprint) use ($identifier): void {
                    $blueprint->dropForeign($identifier);
                });
                $removed[$table] = ($removed[$table] ?? 0) + 1;
            }
        }

        return $removed;
    }
}
