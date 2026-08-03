<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Patients\Domain\Enums\PatientIdentifierType;
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

    public function test_reception_creates_idempotent_multi_exam_request_and_grid_entry(): void
    {
        [$unit, $receptionist, $patient] = $this->context();
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Dra. Solicitante', 'must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient->identifiers()->create([
            'type' => PatientIdentifierType::Cpf,
            'normalized_value' => '52998224725',
            'display_value' => '529.982.247-25',
            'is_primary' => true,
        ]);
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $hemogram = $integration->exams()->create(['external_code' => '127', 'name' => 'Hemograma completo']);
        $glucose = $integration->exams()->create(['external_code' => '128', 'name' => 'Glicose']);
        $payload = [
            ...$this->payload($patient),
            'request_exams' => '1',
            'exam_requester_id' => $doctor->getKey(),
            'exam_priority' => 'routine',
            'exam_clinical_indication' => 'Investigação laboratorial registrada na recepção.',
            'exam_ids' => [$hemogram->getKey(), $glucose->getKey()],
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('reception.store'), $payload)
            ->assertRedirect();

        $order = ExamOrder::query()->with('items')->sole();
        $this->assertSame('reception', $order->origin);
        $this->assertSame($doctor->getKey(), $order->requested_by);
        $this->assertSame($receptionist->getKey(), $order->created_by);
        $this->assertNull($order->medical_consultation_id);
        $this->assertSame(['Hemograma completo', 'Glicose'], $order->items->pluck('exam_name')->all());
        $this->assertDatabaseHas('laboratory_order_transmissions', [
            'exam_order_id' => $order->getKey(),
            'status' => 'awaiting_configuration',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.order_created_at_reception']);

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('reception.store'), $payload)
            ->assertRedirect();
        $this->assertDatabaseCount('exam_orders', 1);

        $this->actingAs($receptionist)->withSession($session)
            ->get(route('laboratory.orders.index'))
            ->assertOk()
            ->assertSee('Requisições de exames')
            ->assertSee('Paciente Demonstrativo')
            ->assertSee('Dra. Solicitante')
            ->assertSee('min-w-[1280px]', false)
            ->assertSee('min-w-24 items-center justify-center whitespace-nowrap', false)
            ->assertSee('2');

        $otherUnit = $this->createHealthUnit('NORTH');
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $otherUnit->getKey()])
            ->get(route('laboratory.orders.index'))
            ->assertOk()
            ->assertDontSee('Paciente Demonstrativo');
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $otherUnit->getKey()])
            ->get(route('laboratory.orders.show', $order))
            ->assertNotFound();
    }

    public function test_reception_cancels_pending_order_without_deleting_history(): void
    {
        [$unit, $receptionist, $patient] = $this->context();
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient->identifiers()->create([
            'type' => PatientIdentifierType::Cns,
            'normalized_value' => '898001160025192',
            'display_value' => '898 0011 6002 5192',
            'is_primary' => true,
        ]);
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'is_active' => true,
        ]);
        $exam = $integration->exams()->create(['external_code' => '127', 'name' => 'Hemograma completo']);
        $payload = [
            ...$this->payload($patient),
            'request_exams' => true,
            'exam_requester_id' => $doctor->getKey(),
            'exam_priority' => 'urgent',
            'exam_clinical_indication' => 'Investigação clínica urgente informada.',
            'exam_ids' => [$exam->getKey()],
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];
        $this->actingAs($receptionist)->withSession($session)->post(route('reception.store'), $payload);
        $order = ExamOrder::query()->sole();

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('laboratory.orders.cancel', $order), [
                'reason' => 'Solicitação cancelada antes da coleta por orientação do solicitante.',
                'confirmation' => '1',
            ])
            ->assertRedirect(route('laboratory.orders.index'));

        $this->assertSame('cancelled', $order->fresh()?->status);
        $this->assertDatabaseCount('exam_orders', 1);
        $this->assertDatabaseHas('exam_order_items', ['exam_order_id' => $order->getKey(), 'status' => 'cancelled']);
        $this->assertDatabaseHas('laboratory_order_transmissions', [
            'exam_order_id' => $order->getKey(),
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.order_cancelled']);
    }

    public function test_reception_draft_is_restored_after_creating_a_provisional_patient(): void
    {
        [$unit, $receptionist] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $department = Department::query()->where('code', 'TRIAGE')->sole();
        $queueId = Queue::query()->where('department_id', $department->getKey())->value('id');
        $idempotencyKey = (string) Str::ulid();

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('reception.draft.provisional'), [
                'idempotency_key' => $idempotencyKey,
                'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
                'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
                'arrival_at' => '2026-08-03T14:30',
                'origin' => 'Unidade de origem informada',
                'entry_reason' => 'Dor persistente informada na chegada',
                'reception_notes' => 'Observacao que nao pode ser perdida.',
                'administrative_priority' => 'elderly',
                'department_id' => $department->getKey(),
                'queue_id' => $queueId,
                'companion_name' => 'Acompanhante preservado',
                'companion_relationship' => 'Filha',
                'request_exams' => '1',
                'exam_priority' => 'urgent',
                'exam_clinical_indication' => 'Indicacao clinica preservada no rascunho.',
                'exam_ids' => [11, 12],
                '_reception_step' => 2,
            ])
            ->assertRedirect(route('patients.provisional.create'));

        $response = $this->actingAs($receptionist)->withSession($session)
            ->post(route('patients.provisional.store'), [
                'full_name' => 'Paciente temporario',
                'sex' => 'unknown',
                'estimated_age' => 70,
                'estimated_age_range' => 'elderly',
                'provisional_description' => 'Paciente sem documentos durante o acolhimento.',
            ]);

        $patient = Patient::query()->where('full_name', 'Paciente temporario')->sole();
        $response->assertRedirect(route('reception.create', ['patient' => $patient->public_id]));
        $response->assertSessionHas('_old_input.idempotency_key', $idempotencyKey);
        $response->assertSessionHas('_old_input.entry_reason', 'Dor persistente informada na chegada');
        $response->assertSessionHas('_old_input.reception_notes', 'Observacao que nao pode ser perdida.');
        $response->assertSessionHas('_old_input.department_id', $department->getKey());
        $response->assertSessionHas('_old_input.queue_id', $queueId);
        $response->assertSessionHas('_old_input.companion_name', 'Acompanhante preservado');
        $response->assertSessionHas('_old_input.request_exams', true);
        $response->assertSessionHas('_old_input.exam_ids', [11, 12]);
        $response->assertSessionHas('_old_input._reception_step', 2);

        $this->actingAs($receptionist)->withSession($session)
            ->get(route('reception.create', ['patient' => $patient->public_id]))
            ->assertOk()
            ->assertSee('Paciente temporario')
            ->assertSee('Dor persistente informada na chegada')
            ->assertSee('Observacao que nao pode ser perdida.')
            ->assertSee('Acompanhante preservado')
            ->assertSee('step: 2', false);
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
