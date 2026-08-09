<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Professionals\Application\Services\ProfessionalOperationalAssignments;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TenantEntryCancellationAndVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_patient_records_are_isolated_by_organization_and_cpf_stays_globally_unique(): void
    {
        $unitA = $this->createHealthUnit('ORG-A');
        $unitB = $this->createHealthUnit('ORG-B');
        $this->seed(RolePermissionSeeder::class);
        $userA = $this->createUserWithUnit($unitA);
        $userA->assignRole('receptionist');
        $userB = $this->createUserWithUnit($unitB);
        $userB->assignRole('receptionist');
        $patient = $this->patient($unitA, $userA->getKey(), 'P-TENANT-1');
        $patient->identifiers()->create([
            'type' => 'cpf',
            'normalized_value' => '52998224725',
            'display_value' => '52998224725',
            'is_primary' => true,
        ]);

        $this->actingAs($userB)->withSession(['active_health_unit_id' => $unitB->getKey()])
            ->get(route('patients.show', $patient))
            ->assertNotFound();
        $this->actingAs($userB)->withSession(['active_health_unit_id' => $unitB->getKey()])
            ->get(route('patients.index'))
            ->assertDontSee($patient->full_name);

        $this->expectException(QueryException::class);
        $other = $this->patient($unitB, $userB->getKey(), 'P-TENANT-2');
        $other->identifiers()->create([
            'type' => 'cpf',
            'normalized_value' => '52998224725',
            'display_value' => '52998224725',
            'is_primary' => true,
        ]);
    }

    public function test_entry_type_rules_and_cancellation_are_enforced_and_audited(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $user = $this->createUserWithUnit($unit);
        $user->assignRole('receptionist');
        $patient = $this->patient($unit, $user->getKey(), 'P-ENTRY-1');
        $triageQueue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();
        $medicalQueue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-CLINIC')->sole();
        $return = EntryType::query()->where('code', 'RETURN')->sole();
        $session = ['active_health_unit_id' => $unit->getKey()];

        $payload = $this->receptionPayload($patient, $return, $triageQueue);
        $this->actingAs($user)->withSession($session)->post(route('reception.store'), $payload)
            ->assertSessionHasErrors('queue_id');

        $payload = $this->receptionPayload($patient, $return, $medicalQueue);
        $this->actingAs($user)->withSession($session)->post(route('reception.store'), $payload)
            ->assertRedirect();
        $encounter = Encounter::query()->sole();
        $this->assertSame(EncounterStatus::WaitingMedical, $encounter->current_status);

        $this->actingAs($user)->withSession($session)
            ->post(route('reception.cancel', $encounter), [
                'version' => $encounter->lock_version,
                'reason' => 'Atendimento aberto de forma equivocada.',
                'confirmation' => true,
            ])->assertRedirect();
        $this->assertDatabaseHas('encounters', [
            'id' => $encounter->getKey(),
            'current_status' => 'cancelled',
            'closed_by' => $user->getKey(),
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'encounter_id' => $encounter->getKey(),
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'encounter.cancelled']);
    }

    public function test_nurse_and_doctor_only_see_their_operational_queues_and_doctor_specialties(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $nurse = $this->createUserWithUnit($unit);
        $nurse->assignRole('triage_professional');
        $this->registerTriageProfessional($nurse, $unit);
        $doctor = $this->createUserWithUnit($unit);
        $doctor->assignRole('doctor');
        $clinical = Specialty::query()->where('code', 'CLINICA')->sole();
        $profile = HealthProfessional::query()->create([
            'organization_id' => $unit->organization_id,
            'user_id' => $doctor->getKey(),
            'institutional_code' => 'MED-QUEUE-1',
            'profession_type' => 'doctor',
            'full_name' => $doctor->name,
            'is_active' => true,
            'created_by' => $doctor->getKey(),
            'updated_by' => $doctor->getKey(),
        ]);
        $profile->healthUnits()->attach($unit);
        $profile->specialties()->attach($clinical, ['is_primary' => true]);

        $triage = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();
        $triagePoint = $triage->servicePoints()->sole();
        $triageTwo = $triage->replicate(['public_id']);
        $triageTwo->fill(['code' => 'QUEUE-TRIAGE-2', 'name' => 'Fila de Triagem 2', 'display_order' => 2])->save();
        $triagePointTwo = $triagePoint->replicate(['public_id']);
        $triagePointTwo->fill(['code' => 'TRIAGE-POINT-2', 'name' => 'Ponto 02 · Triagem 02'])->save();
        $triageTwo->servicePoints()->attach($triagePointTwo);
        $nurseProfile = $nurse->professionalProfile()->sole();
        $assignments = app(ProfessionalOperationalAssignments::class);
        $assignments->sync($nurseProfile, [(int) $triage->getKey()], [(int) $triagePoint->getKey()]);
        $clinic = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-CLINIC')->sole();
        $pediatrics = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-PEDIATRICS')->sole();
        $assignments->sync(
            $profile,
            [(int) $clinic->getKey()],
            $clinic->servicePoints()->pluck('service_points.id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($nurse)->withSession($session)->get(route('queues.index'))
            ->assertOk()
            ->assertSee($triage->name)
            ->assertSee($triagePoint->name)
            ->assertDontSee($triageTwo->name)
            ->assertDontSee($triagePointTwo->name)
            ->assertDontSee('href="'.route('triage.queue').'"', false)
            ->assertDontSee('href="'.route('medical.queue').'"', false)
            ->assertDontSee($clinic->name);
        $this->actingAs($nurse)->withSession($session)->get(route('queues.entries', $clinic))
            ->assertNotFound();
        $this->actingAs($nurse)->withSession($session)->get(route('queues.entries', $triageTwo))
            ->assertNotFound();

        $this->actingAs($doctor)->withSession($session)->get(route('queues.index'))
            ->assertOk()->assertSee($clinic->name)->assertDontSee($triage->name)->assertDontSee($pediatrics->name);
        $this->actingAs($doctor)->withSession($session)->get(route('queues.entries', $pediatrics))
            ->assertNotFound();
    }

    private function patient($unit, int $userId, string $record): Patient
    {
        return Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => $record,
            'full_name' => 'Paciente '.$record,
            'normalized_name' => 'PACIENTE '.$record,
            'birth_date' => '1990-01-01',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /** @return array<string, mixed> */
    private function receptionPayload(Patient $patient, EntryType $entryType, Queue $queue): array
    {
        return [
            'idempotency_key' => (string) Str::ulid(),
            'patient_public_id' => $patient->public_id,
            'entry_type_id' => $entryType->getKey(),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'entry_reason' => 'Retorno para avaliacao medica',
            'administrative_priority' => AdministrativePriority::None->value,
            'department_id' => $queue->department_id,
            'queue_id' => $queue->getKey(),
            'specialty_id' => $queue->specialty_id,
        ];
    }
}
