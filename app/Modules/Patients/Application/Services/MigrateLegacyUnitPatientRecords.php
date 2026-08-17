<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use App\Modules\Patients\Infrastructure\Eloquent\PatientUnitMigrationConflict;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MigrateLegacyUnitPatientRecords
{
    /** @var list<string> */
    public const LOCAL_TABLES = [
        'patient_contacts',
        'patient_addresses',
        'patient_guardians',
        'patient_allergies',
        'patient_conditions',
        'patient_medications',
        'patient_social_histories',
    ];

    /** @return array{unit_patients: int, participations: int, migrated_records: int, conflicts: int} */
    public function execute(string $tenantConnection, bool $apply = false): array
    {
        $tenant = DB::connection($tenantConnection);
        $core = DB::connection('core');
        $patients = $core->table('patients')->pluck('public_id', 'id')
            ->mapWithKeys(fn (mixed $publicId, mixed $id): array => [(int) $id => (string) $publicId])
            ->all();
        $units = $core->table('health_units')->pluck('public_id', 'id')
            ->mapWithKeys(fn (mixed $publicId, mixed $id): array => [(int) $id => (string) $publicId])
            ->all();

        /** @var array<int, array<string, true>> $patientUnits */
        $patientUnits = [];
        $encounters = $tenant->table('encounters')
            ->select(['patient_id', 'patient_public_id', 'health_unit_id', 'health_unit_public_id', 'created_at'])
            ->whereNotNull('patient_id')
            ->get();
        foreach ($encounters as $encounter) {
            $patientId = (int) $encounter->patient_id;
            $patientPublicId = (string) ($encounter->patient_public_id ?: ($patients[$patientId] ?? ''));
            $unitPublicId = (string) ($encounter->health_unit_public_id ?: ($units[(int) $encounter->health_unit_id] ?? ''));
            if ($patientPublicId === '' || $unitPublicId === '') {
                continue;
            }
            $patientUnits[$patientId][$unitPublicId] = true;
        }

        $result = ['unit_patients' => 0, 'participations' => 0, 'migrated_records' => 0, 'conflicts' => 0];
        foreach ($patientUnits as $patientId => $unitMap) {
            $patientPublicId = $patients[$patientId] ?? null;
            if ($patientPublicId === null) {
                continue;
            }
            foreach (array_keys($unitMap) as $unitPublicId) {
                $result['unit_patients']++;
                $result['participations']++;
                if (! $apply) {
                    continue;
                }
                $now = now();
                $unitPatientQuery = $tenant->table('unit_patients')
                    ->where('patient_public_id', $patientPublicId)
                    ->where('health_unit_public_id', $unitPublicId);
                if (! $unitPatientQuery->exists()) {
                    $tenant->table('unit_patients')->insert([
                        'public_id' => (string) Str::ulid(),
                        'patient_public_id' => $patientPublicId,
                        'health_unit_public_id' => $unitPublicId,
                        'status' => 'active',
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $unitPatientQuery->update(['last_seen_at' => $now, 'updated_at' => $now]);
                }
                $participation = $core->table('patient_unit_participations')
                    ->where('patient_public_id', $patientPublicId)
                    ->where('health_unit_public_id', $unitPublicId);
                if (! $participation->exists()) {
                    $core->table('patient_unit_participations')->insert([
                        'patient_public_id' => $patientPublicId,
                        'health_unit_public_id' => $unitPublicId,
                        'tenant_connection' => $tenantConnection,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                } else {
                    $participation->update([
                        'tenant_connection' => $tenantConnection,
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }

        foreach (self::LOCAL_TABLES as $tableName) {
            $tenant->table($tableName)
                ->whereNull('unit_patient_id')
                ->orderBy('id')
                ->chunkById(250, function ($records) use (
                    $apply,
                    $patients,
                    $patientUnits,
                    $tableName,
                    $tenant,
                    $tenantConnection,
                    &$result,
                ): void {
                    foreach ($records as $record) {
                        $patientId = (int) $record->patient_id;
                        $patientPublicId = $patients[$patientId] ?? null;
                        $candidateUnits = array_keys($patientUnits[$patientId] ?? []);
                        if ($patientPublicId !== null && count($candidateUnits) === 1) {
                            $result['migrated_records']++;
                            if ($apply) {
                                $unitPublicId = $candidateUnits[0];
                                $unitPatientId = $tenant->table('unit_patients')
                                    ->where('patient_public_id', $patientPublicId)
                                    ->where('health_unit_public_id', $unitPublicId)
                                    ->value('id');
                                $tenant->table($tableName)->where('id', $record->id)->update([
                                    'unit_patient_id' => $unitPatientId,
                                    'patient_public_id' => $patientPublicId,
                                    'health_unit_public_id' => $unitPublicId,
                                    'updated_at' => now(),
                                ]);
                            }

                            continue;
                        }

                        $result['conflicts']++;
                        if ($apply) {
                            $this->recordConflict(
                                $tableName,
                                (int) $record->id,
                                $tenantConnection,
                                $patientPublicId,
                                $patientPublicId === null
                                    ? 'missing_core_patient'
                                    : ($candidateUnits === [] ? 'no_unit' : 'multiple_units'),
                                $candidateUnits,
                            );
                        }
                    }
                });
        }

        return $result;
    }

    /** @param list<string> $candidateUnits */
    private function recordConflict(
        string $sourceTable,
        int $sourceId,
        string $tenantConnection,
        ?string $patientPublicId,
        string $reason,
        array $candidateUnits,
    ): void {
        PatientUnitMigrationConflict::query()->firstOrCreate(
            [
                'tenant_connection' => $tenantConnection,
                'source_table' => $sourceTable,
                'source_id' => $sourceId,
            ],
            [
                'patient_public_id' => $patientPublicId,
                'reason' => $reason,
                'candidate_health_unit_public_ids' => $candidateUnits,
                'status' => 'pending',
            ],
        );
    }
}
