<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * @phpstan-type Definition array{table: string, scope: string, column?: string|null, legacy_column?: string|null, parent?: string, unique: list<string>}
 */
final class TenantDataSet
{
    /**
     * @return list<Definition>
     */
    public function definitions(): array
    {
        return [
            $this->all('number_sequences'),
            $this->organization('arrival_methods'),
            $this->organization('entry_types'),
            $this->all('triage_protocols'),
            $this->parent('triage_flowcharts', 'triage_protocol_id', 'triage_protocols'),
            $this->parent('triage_discriminators', 'triage_flowchart_id', 'triage_flowcharts'),
            $this->all('vital_sign_ranges'),
            $this->unit('departments'),
            $this->parent('rooms', 'department_id', 'departments'),
            $this->parent('service_points', 'room_id', 'rooms'),
            $this->unit('queues'),
            $this->parent('queue_service_point', 'queue_id', 'queues', ['queue_id', 'service_point_id']),
            $this->parent('queue_sequences', 'queue_id', 'queues'),
            $this->unit('panels'),
            $this->parent('panel_queue', 'panel_id', 'panels', ['panel_id', 'queue_id']),
            $this->parent('health_professional_queue', 'queue_id', 'queues', ['health_professional_id', 'queue_id']),
            $this->parent(
                'health_professional_service_point',
                'service_point_id',
                'service_points',
                ['health_professional_id', 'service_point_id'],
            ),
            $this->unit('unit_patients'),
            $this->unit('patient_contacts'),
            $this->unit('patient_addresses'),
            $this->unit('patient_guardians'),
            $this->unit('patient_allergies'),
            $this->unit('patient_conditions'),
            $this->unit('patient_medications'),
            $this->unit('patient_social_histories'),
            $this->unit('encounters'),
            $this->unit('idempotency_keys', 'health_unit_public_id', null),
            $this->parent('reception_records', 'encounter_id', 'encounters'),
            $this->parent('encounter_companions', 'encounter_id', 'encounters'),
            $this->parent('encounter_status_history', 'encounter_id', 'encounters'),
            $this->unit('patient_access_logs', null, 'health_unit_id'),
            $this->parent('queue_entries', 'encounter_id', 'encounters'),
            $this->parent('queue_calls', 'queue_entry_id', 'queue_entries'),
            $this->parent('queue_entry_history', 'queue_entry_id', 'queue_entries'),
            $this->parent('queue_transfers', 'encounter_id', 'encounters'),
            $this->parent('triage_assessments', 'encounter_id', 'encounters'),
            $this->parent('vital_sign_measurements', 'triage_assessment_id', 'triage_assessments'),
            $this->parent('triage_addenda', 'triage_assessment_id', 'triage_assessments'),
            $this->parent('medical_consultations', 'encounter_id', 'encounters'),
            $this->parent('physical_exams', 'medical_consultation_id', 'medical_consultations'),
            $this->parent('diagnoses', 'medical_consultation_id', 'medical_consultations'),
            $this->parent('prescriptions', 'medical_consultation_id', 'medical_consultations'),
            $this->parent('prescription_items', 'prescription_id', 'prescriptions'),
            $this->unit('exam_orders'),
            $this->parent('exam_order_items', 'exam_order_id', 'exam_orders'),
            $this->parent('exam_results', 'exam_order_item_id', 'exam_order_items'),
            $this->parent('clinical_notes', 'medical_consultation_id', 'medical_consultations'),
            $this->parent('referrals', 'medical_consultation_id', 'medical_consultations'),
            $this->parent('encounter_destinations', 'encounter_id', 'encounters'),
            $this->parent('medical_addenda', 'medical_consultation_id', 'medical_consultations'),
            $this->unit('documents'),
            $this->parent('document_versions', 'document_id', 'documents'),
            $this->unit('medical_certificates'),
            $this->unit('laboratory_integrations'),
            $this->parent('laboratory_materials', 'laboratory_integration_id', 'laboratory_integrations'),
            $this->parent('laboratory_exams', 'laboratory_integration_id', 'laboratory_integrations'),
            $this->parent('laboratory_exam_components', 'laboratory_exam_id', 'laboratory_exams'),
            $this->parent('exam_mappings', 'laboratory_integration_id', 'laboratory_integrations'),
            $this->unit('health_unit_exams'),
            $this->parent('exam_catalog_import_candidates', 'laboratory_integration_id', 'laboratory_integrations'),
            $this->parent('exam_group_import_conflicts', 'laboratory_integration_id', 'laboratory_integrations'),
            $this->unit('laboratory_order_transmissions'),
            $this->parent(
                'laboratory_transmission_attempts',
                'laboratory_order_transmission_id',
                'laboratory_order_transmissions',
            ),
            $this->parent('laboratory_result_ingestions', 'laboratory_integration_id', 'laboratory_integrations'),
            $this->unit('medical_shift_attendances'),
            $this->unit('audit_logs', null, 'health_unit_id'),
        ];
    }

    /**
     * @param  Definition  $definition
     * @param  array<string, list<int|string>>  $ids
     */
    public function sourceQuery(
        Connection $connection,
        array $definition,
        HealthUnit $unit,
        array $ids,
    ): Builder {
        $query = $connection->table($definition['table']);

        return match ($definition['scope']) {
            'all' => $query,
            'organization' => $query->where(function (Builder $query) use ($connection, $definition, $unit): void {
                $publicColumn = array_key_exists('column', $definition)
                    ? $definition['column']
                    : 'organization_public_id';
                $legacyColumn = array_key_exists('legacy_column', $definition)
                    ? $definition['legacy_column']
                    : 'organization_id';
                $hasLegacyColumn = $legacyColumn !== null
                    && $connection->getSchemaBuilder()->hasColumn($definition['table'], $legacyColumn);
                if ($publicColumn !== null
                    && $connection->getSchemaBuilder()->hasColumn($definition['table'], $publicColumn)) {
                    $query->where($publicColumn, $unit->organization?->public_id);
                    if ($hasLegacyColumn) {
                        $query->orWhere(function (Builder $query) use ($publicColumn, $legacyColumn, $unit): void {
                            $query->whereNull($publicColumn)
                                ->where($legacyColumn, $unit->organization_id);
                        });
                    }
                } elseif ($hasLegacyColumn) {
                    $query->where($legacyColumn, $unit->organization_id);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }),
            'unit' => $query->where(function (Builder $query) use ($connection, $definition, $unit): void {
                $publicColumn = array_key_exists('column', $definition)
                    ? $definition['column']
                    : 'health_unit_public_id';
                $legacyColumn = array_key_exists('legacy_column', $definition)
                    ? $definition['legacy_column']
                    : 'health_unit_id';
                $hasLegacyColumn = $legacyColumn !== null
                    && $connection->getSchemaBuilder()->hasColumn($definition['table'], $legacyColumn);
                if ($publicColumn !== null
                    && $connection->getSchemaBuilder()->hasColumn($definition['table'], $publicColumn)) {
                    $query->where($publicColumn, $unit->public_id);
                    if ($hasLegacyColumn) {
                        $query->orWhere(function (Builder $query) use ($publicColumn, $legacyColumn, $unit): void {
                            $query->whereNull($publicColumn)->where($legacyColumn, $unit->getKey());
                        });
                    }
                } elseif ($hasLegacyColumn) {
                    $query->where($legacyColumn, $unit->getKey());
                } else {
                    $query->whereRaw('1 = 0');
                }
            }),
            'parent' => $query->whereIn(
                (string) $definition['column'],
                $ids[(string) $definition['parent']] ?? [],
            ),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * @param  list<string>  $unique
     * @return Definition
     */
    private function all(string $table, array $unique = ['id']): array
    {
        return ['table' => $table, 'scope' => 'all', 'unique' => $unique];
    }

    /** @return Definition */
    private function organization(string $table): array
    {
        return [
            'table' => $table,
            'scope' => 'organization',
            'column' => 'organization_public_id',
            'legacy_column' => 'organization_id',
            'unique' => ['id'],
        ];
    }

    /**
     * @param  list<string>  $unique
     * @return Definition
     */
    private function unit(
        string $table,
        ?string $publicColumn = 'health_unit_public_id',
        ?string $legacyColumn = 'health_unit_id',
        array $unique = ['id'],
    ): array {
        return [
            'table' => $table,
            'scope' => 'unit',
            'column' => $publicColumn,
            'legacy_column' => $legacyColumn,
            'unique' => $unique,
        ];
    }

    /**
     * @param  list<string>  $unique
     * @return Definition
     */
    private function parent(string $table, string $column, string $parent, array $unique = ['id']): array
    {
        return [
            'table' => $table,
            'scope' => 'parent',
            'column' => $column,
            'parent' => $parent,
            'unique' => $unique,
        ];
    }
}
