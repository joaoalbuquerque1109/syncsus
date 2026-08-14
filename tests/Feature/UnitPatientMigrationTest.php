<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Patients\Application\Actions\SavePatientAction;
use App\Modules\Patients\Application\Services\MigrateLegacyUnitPatientRecords;
use App\Modules\Patients\Application\Services\ResolveUnitPatientMigrationConflict;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientContact;
use App\Modules\Patients\Infrastructure\Eloquent\PatientUnitMigrationConflict;
use Database\Seeders\OperationalCatalogSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class UnitPatientMigrationTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_new_patient_data_is_local_to_the_active_unit_and_identifier_is_protected(): void
    {
        $unit = $this->createHealthUnit('UNIT-PATIENT-A');
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $patient = app(SavePatientAction::class)->execute([
            'idempotency_key' => (string) Str::ulid(),
            'full_name' => 'Paciente Local',
            'birth_date' => '1990-01-01',
            'sex' => 'unknown',
            'mother_unknown' => true,
            'cpf' => '52998224725',
            'mobile' => '85999999999',
        ], $user, (int) $unit->getKey());

        $identifier = $patient->identifiers()->sole();
        $this->assertNotNull($identifier->encrypted_value);
        $this->assertNotSame('52998224725', $identifier->encrypted_value);
        $this->assertSame(
            hash_hmac('sha256', 'cpf:52998224725', 'phase-2-test-key-not-for-production'),
            $identifier->fingerprint,
        );
        $this->assertDatabaseHas('unit_patients', [
            'patient_public_id' => $patient->public_id,
            'health_unit_public_id' => $unit->public_id,
        ]);
        $this->assertDatabaseHas('patient_unit_participations', [
            'patient_public_id' => $patient->public_id,
            'health_unit_public_id' => $unit->public_id,
            'tenant_connection' => 'tenant_test',
        ]);

        $otherUnit = HealthUnit::query()->create([
            'organization_id' => $unit->organization_id,
            'code' => 'UNIT-PATIENT-B',
            'cnes_code' => '7654321',
            'name' => 'Unidade B',
            'is_active' => true,
        ]);
        $this->activateTenant($otherUnit);
        $this->assertSame(0, PatientContact::query()->where('patient_id', $patient->getKey())->count());
        $this->activateTenant($unit);
        $this->assertSame(1, PatientContact::query()->where('patient_id', $patient->getKey())->count());
    }

    public function test_backfill_migrates_only_unambiguous_records_and_is_idempotent(): void
    {
        $firstUnit = $this->createHealthUnit('BACKFILL-A');
        $user = $this->createUserWithUnit($firstUnit, ['must_change_password' => false]);
        $secondUnit = HealthUnit::query()->create([
            'organization_id' => $firstUnit->organization_id,
            'code' => 'BACKFILL-B',
            'cnes_code' => '7654322',
            'name' => 'Unidade B',
            'is_active' => true,
        ]);
        $this->seed(OperationalCatalogSeeder::class);
        $unambiguous = $this->createPatient('P90000001', 'Paciente Inequívoco', $firstUnit, $user->getKey());
        $ambiguous = $this->createPatient('P90000002', 'Paciente Ambíguo', $firstUnit, $user->getKey());
        $this->insertEncounter($unambiguous, $firstUnit, (int) $user->getKey(), 'MIG-001');
        $this->insertEncounter($ambiguous, $firstUnit, (int) $user->getKey(), 'MIG-002');
        $this->insertEncounter($ambiguous, $secondUnit, (int) $user->getKey(), 'MIG-003');

        $unambiguousContactId = DB::connection('tenant_test')->table('patient_contacts')->insertGetId([
            'patient_id' => $unambiguous->getKey(),
            'type' => 'mobile',
            'value' => '11111111111',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ambiguousContactId = DB::connection('tenant_test')->table('patient_contacts')->insertGetId([
            'patient_id' => $ambiguous->getKey(),
            'type' => 'mobile',
            'value' => '22222222222',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = app(MigrateLegacyUnitPatientRecords::class)->execute('tenant_test', true);
        $this->assertSame(1, $result['migrated_records']);
        $this->assertSame(1, $result['conflicts']);
        $this->assertNotNull(DB::connection('tenant_test')->table('patient_contacts')
            ->where('id', $unambiguousContactId)->value('unit_patient_id'));
        $this->assertNull(DB::connection('tenant_test')->table('patient_contacts')
            ->where('id', $ambiguousContactId)->value('unit_patient_id'));
        $this->assertDatabaseHas('patient_unit_migration_conflicts', [
            'source_table' => 'patient_contacts',
            'source_id' => $ambiguousContactId,
            'reason' => 'multiple_units',
            'status' => 'pending',
        ]);

        app(MigrateLegacyUnitPatientRecords::class)->execute('tenant_test', true);
        $this->assertDatabaseCount('unit_patients', 3);
        $this->assertDatabaseCount('patient_unit_participations', 3);
        $this->assertDatabaseCount('patient_unit_migration_conflicts', 1);

        $conflict = PatientUnitMigrationConflict::query()->sole();
        $resolver = app(ResolveUnitPatientMigrationConflict::class);
        $actor = $this->createPlatformAdministrator();
        $resolved = $resolver->execute(
            $conflict,
            $firstUnit,
            $actor,
            Request::create('/tests/patients/resolve-unit-conflict', 'POST'),
        );
        $this->assertSame('resolved', $resolved->status);
        $this->assertSame($firstUnit->public_id, $resolved->resolved_health_unit_public_id);
        $this->assertNotNull(DB::connection('tenant_test')->table('patient_contacts')
            ->where('id', $ambiguousContactId)->value('unit_patient_id'));

        $audit = AuditLog::query()
            ->where('action', 'patient.unit_migration_conflict_resolved')
            ->sole();
        $this->assertSame($actor->getKey(), $audit->user_id);
        $this->assertSame($firstUnit->getKey(), $audit->health_unit_id);
        $this->assertSame($conflict->public_id, $audit->context['conflict'] ?? null);
        $this->assertSame('patient_contacts', $audit->context['source_table'] ?? null);
        $this->assertSame($ambiguousContactId, $audit->context['source_id'] ?? null);
        $this->assertSame($firstUnit->public_id, $audit->context['resolved_unit'] ?? null);
    }

    private function createPatient(
        string $recordNumber,
        string $name,
        HealthUnit $unit,
        int|string $userId,
    ): Patient {
        return Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => $recordNumber,
            'full_name' => $name,
            'normalized_name' => mb_strtoupper($name),
            'sex' => 'unknown',
            'reference_health_unit_id' => $unit->getKey(),
            'status' => 'active',
            'created_by' => $userId,
        ]);
    }

    private function insertEncounter(Patient $patient, HealthUnit $unit, int $userId, string $number): void
    {
        $entryTypeId = EntryType::query()
            ->where('organization_id', $unit->organization_id)
            ->where('code', 'EMERGENCY')
            ->value('id');
        $arrivalMethodId = ArrivalMethod::query()
            ->where('organization_id', $unit->organization_id)
            ->where('code', 'WALK_IN')
            ->value('id');
        DB::connection('tenant_test')->table('encounters')->insert([
            'public_id' => (string) Str::ulid(),
            'encounter_number' => $number,
            'patient_id' => $patient->getKey(),
            'patient_public_id' => $patient->public_id,
            'health_unit_id' => $unit->getKey(),
            'health_unit_public_id' => $unit->public_id,
            'entry_type_id' => $entryTypeId,
            'arrival_method_id' => $arrivalMethodId,
            'current_status' => 'registered',
            'administrative_priority' => 'none',
            'arrival_at' => now(),
            'registration_at' => now(),
            'lock_version' => 1,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
