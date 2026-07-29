<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReceptionOpeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_opening_is_atomic_audited_and_idempotent(): void
    {
        [$unit, $user, $patient] = $this->context();
        $payload = $this->payload($patient);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($user)
            ->withSession($session)
            ->get(route('reception.create', ['patient' => $patient->public_id]))
            ->assertOk()
            ->assertSee('Abrir atendimento')
            ->assertSee($patient->full_name);

        $first = $this->actingAs($user)->withSession($session)->post(route('reception.store'), $payload);
        $encounter = Encounter::query()->sole();
        $first->assertRedirect(route('reception.receipt', $encounter));

        $this->assertSame('CENTRAL-'.now()->format('Ymd').'-0001', $encounter->encounter_number);
        $this->assertDatabaseHas('queue_entries', [
            'encounter_id' => $encounter->getKey(),
            'ticket_number' => 'T001',
            'status' => 'waiting',
        ]);
        $this->assertDatabaseCount('encounter_status_history', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'encounter.opened',
            'patient_id' => $patient->getKey(),
            'encounter_id' => $encounter->getKey(),
            'health_unit_id' => $unit->getKey(),
        ]);

        $this->actingAs($user)->withSession($session)->post(route('reception.store'), $payload)
            ->assertRedirect(route('reception.receipt', $encounter));
        $this->assertDatabaseCount('encounters', 1);
        $this->assertDatabaseCount('queue_entries', 1);

        $this->actingAs($user)->withSession($session)->get(route('reception.receipt', $encounter))
            ->assertOk()
            ->assertSee('T001')
            ->assertSee($patient->full_name);
    }

    public function test_active_duplicate_and_reused_key_with_different_payload_are_rejected(): void
    {
        [$unit, $user, $patient] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $payload = $this->payload($patient);

        $this->actingAs($user)->withSession($session)->post(route('reception.store'), $payload)->assertRedirect();

        $this->actingAs($user)->withSession($session)->post(route('reception.store'), [
            ...$payload,
            'entry_reason' => 'Motivo diferente',
        ])->assertSessionHasErrors('idempotency_key');

        $this->actingAs($user)->withSession($session)->post(route('reception.store'), [
            ...$payload,
            'idempotency_key' => (string) Str::ulid(),
        ])->assertSessionHasErrors('patient_public_id');
        $this->assertDatabaseCount('encounters', 1);
    }

    public function test_vehicle_information_is_required_for_ambulance_arrival(): void
    {
        [$unit, $user, $patient] = $this->context();
        $payload = $this->payload($patient);
        $payload['arrival_method_id'] = ArrivalMethod::query()->where('code', 'SAMU')->value('id');

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('reception.store'), $payload)
            ->assertSessionHasErrors('vehicle_information');
        $this->assertDatabaseCount('encounters', 0);
    }

    /** @return array{0: mixed, 1: mixed, 2: Patient} */
    private function context(): array
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $user->assignRole('receptionist');
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000001',
            'full_name' => 'Paciente Demonstrativo',
            'normalized_name' => 'PACIENTE DEMONSTRATIVO',
            'birth_date' => '1990-01-10',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);

        return [$unit, $user, $patient];
    }

    /** @return array<string, mixed> */
    private function payload(Patient $patient): array
    {
        $department = Department::query()->where('code', 'TRIAGE')->sole();

        return [
            'idempotency_key' => (string) Str::ulid(),
            'patient_public_id' => $patient->public_id,
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'entry_reason' => 'Dor abdominal há duas horas',
            'administrative_priority' => 'none',
            'department_id' => $department->getKey(),
            'queue_id' => Queue::query()->where('department_id', $department->getKey())->value('id'),
            'companion_name' => 'Acompanhante Demonstrativo',
            'companion_relationship' => 'Irmã',
        ];
    }
}
