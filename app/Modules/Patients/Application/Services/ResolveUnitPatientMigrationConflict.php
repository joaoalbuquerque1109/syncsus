<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientUnitMigrationConflict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

final class ResolveUnitPatientMigrationConflict
{
    public function __construct(private readonly RecordAuditEventAction $audit) {}

    public function execute(
        PatientUnitMigrationConflict $conflict,
        HealthUnit $unit,
        User $actor,
        Request $request,
    ): PatientUnitMigrationConflict {
        if (! in_array($conflict->source_table, MigrateLegacyUnitPatientRecords::LOCAL_TABLES, true)) {
            throw new LogicException('Tabela de origem do conflito não é permitida.');
        }
        if ($conflict->status !== 'pending') {
            throw new LogicException('O conflito já foi resolvido.');
        }
        if (blank($conflict->patient_public_id)) {
            throw new LogicException('O registro órfão exige primeiro reconciliar o paciente Core.');
        }
        $patient = Patient::query()->where('public_id', $conflict->patient_public_id)->firstOrFail();
        if ((int) $patient->organization_id !== (int) $unit->organization_id) {
            throw new LogicException('A unidade escolhida não pertence à organização do paciente.');
        }
        if (! $actor->isPlatformAdministrator()
            && ((int) $actor->organization_id !== (int) $unit->organization_id
                || ! $actor->can('administration.manage'))) {
            throw new LogicException('O responsável não pode reconciliar registros desta organização.');
        }

        $tenant = DB::connection((string) $conflict->tenant_connection);
        $now = now();
        $unitPatientId = $tenant->table('unit_patients')
            ->where('patient_public_id', $conflict->patient_public_id)
            ->where('health_unit_public_id', $unit->public_id)
            ->value('id');
        if ($unitPatientId === null) {
            $unitPatientId = $tenant->table('unit_patients')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'patient_public_id' => $conflict->patient_public_id,
                'health_unit_public_id' => $unit->public_id,
                'status' => 'active',
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $source = $tenant->table((string) $conflict->source_table)->where('id', $conflict->source_id)->first();
        if ($source === null) {
            throw new LogicException('O registro de origem do conflito não existe mais.');
        }
        if ($source->unit_patient_id === null) {
            $tenant->table((string) $conflict->source_table)
                ->where('id', $conflict->source_id)
                ->update([
                    'unit_patient_id' => $unitPatientId,
                    'patient_public_id' => $conflict->patient_public_id,
                    'health_unit_public_id' => $unit->public_id,
                    'updated_at' => $now,
                ]);
        } elseif ((int) $source->unit_patient_id !== (int) $unitPatientId) {
            throw new LogicException('O registro de origem não está mais pendente para reconciliação.');
        }

        DB::connection('core')->table('patient_unit_participations')->updateOrInsert(
            [
                'patient_public_id' => $conflict->patient_public_id,
                'health_unit_public_id' => $unit->public_id,
            ],
            [
                'tenant_connection' => $conflict->tenant_connection,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $conflict->update([
            'status' => 'resolved',
            'resolved_health_unit_public_id' => $unit->public_id,
            'resolved_by_public_id' => $actor->public_id,
            'resolved_at' => $now,
        ]);

        $this->audit->execute(
            'patient.unit_migration_conflict_resolved',
            $request,
            $actor,
            [
                'conflict' => $conflict->public_id,
                'source_table' => $conflict->source_table,
                'source_id' => $conflict->source_id,
                'resolved_unit' => $unit->public_id,
            ],
            (int) $unit->getKey(),
        );

        return $conflict->refresh();
    }
}
