<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Domain\Enums\ExamMappingMatchType;
use App\Modules\Laboratory\Infrastructure\Eloquent\Exam;
use App\Modules\Laboratory\Infrastructure\Eloquent\ExamMapping;
use App\Modules\Laboratory\Infrastructure\Eloquent\HealthUnitExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
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
use Illuminate\Support\Str;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class ReceptionOpeningTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

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
        $this->setLaboratoryExamAvailability($unit, $integration, $hemogram);
        $this->setLaboratoryExamAvailability($unit, $integration, $glucose);
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
            ->assertSee('Paciente Demonstrativo');
        $this->actingAs($receptionist)->withSession($session)
            ->get(route('reception.create'))
            ->assertOk()
            ->assertSee('href="'.route('laboratory.orders.index').'"', false);
        $this->actingAs($doctor)->withSession($session)
            ->get(route('laboratory.orders.index'))
            ->assertForbidden();
        $this->actingAs($receptionist)->withSession($session)
            ->get(route('laboratory.orders.show', $order))
            ->assertOk()
            ->assertSee('Voltar ao fluxo');
        $this->actingAs($doctor)->withSession($session)
            ->get(route('laboratory.orders.show', $order))
            ->assertOk();
        $otherDoctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $otherDoctor->assignRole('doctor');
        $this->actingAs($otherDoctor)->withSession($session)
            ->get(route('laboratory.orders.show', $order))
            ->assertForbidden();

        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $this->actingAs($administrator)->withSession($session)
            ->get(route('laboratory.orders.index'))
            ->assertOk()
            ->assertSee('Requisições de exames')
            ->assertSee('Paciente Demonstrativo')
            ->assertSee('Dra. Solicitante')
            ->assertSee('href="'.route('laboratory.orders.index').'"', false)
            ->assertSee('min-w-[1280px]', false)
            ->assertSee('min-w-24 items-center justify-center whitespace-nowrap', false)
            ->assertSee('2');

        $otherUnit = $this->createHealthUnit('NORTH');
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
        $this->setLaboratoryExamAvailability($unit, $integration, $exam);
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
            ->assertRedirect(route('reception.receipt', $order->encounter_id));

        $this->assertSame('cancelled', $order->fresh()?->status);
        $this->assertDatabaseCount('exam_orders', 1);
        $this->assertDatabaseHas('exam_order_items', ['exam_order_id' => $order->getKey(), 'status' => 'cancelled']);
        $this->assertDatabaseHas('laboratory_order_transmissions', [
            'exam_order_id' => $order->getKey(),
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.order_cancelled']);
    }

    public function test_reception_can_request_exams_without_a_doctor_and_becomes_the_requester(): void
    {
        [$unit, $receptionist, $patient] = $this->context();
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
        $exam = $integration->exams()->create(['external_code' => '127', 'name' => 'Hemograma completo']);
        $this->setLaboratoryExamAvailability($unit, $integration, $exam);
        $payload = [
            ...$this->payload($patient),
            'request_exams' => '1',
            'exam_priority' => 'routine',
            'exam_clinical_indication' => 'Investigação laboratorial sem médico solicitante.',
            'exam_ids' => [$exam->getKey()],
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('reception.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('success', 'Atendimento aberto e requisição de exames registrada com sucesso.');

        $order = ExamOrder::query()->sole();
        $this->assertSame($receptionist->getKey(), $order->requested_by);
        $this->assertSame($receptionist->getKey(), $order->created_by);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'laboratory.order_created_at_reception',
        ]);
    }

    public function test_reception_create_modal_hides_patient_registration_links_and_relaxes_queue_fields(): void
    {
        [$unit, $receptionist] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];

        $full = $this->actingAs($receptionist)->withSession($session)
            ->get(route('reception.create'))
            ->assertOk();
        $full->assertSee('Cadastrar paciente');
        $full->assertSee('criar identificação provisória');
        $full->assertDontSee('recepção de exames padrão da unidade será usada automaticamente');

        $modal = $this->actingAs($receptionist)->withSession($session)
            ->get(route('reception.create', ['modal' => 1, 'request_exams' => 1]))
            ->assertOk();
        $modal->assertDontSee('Cadastrar paciente');
        $modal->assertDontSee('criar identificação provisória');
        $modal->assertSee('Apenas pacientes já cadastrados podem ser usados aqui');
        $modal->assertSee('recepção de exames padrão da unidade será usada automaticamente');
    }

    public function test_reception_without_department_and_queue_falls_back_to_lab_intake_queue_for_non_triage_entry(): void
    {
        [$unit, $user, $patient] = $this->context();
        $payload = [
            'idempotency_key' => (string) Str::ulid(),
            'patient_public_id' => $patient->public_id,
            'entry_type_id' => EntryType::query()->where('code', 'RETURN')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'entry_reason' => 'Retorno apenas para requisitar exames laboratoriais',
            'administrative_priority' => 'none',
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($user)->withSession($session)
            ->post(route('reception.store'), $payload)
            ->assertRedirect();

        $encounter = Encounter::query()->sole();
        $labIntakeQueue = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-LAB_INTAKE')
            ->sole();
        $this->assertSame($labIntakeQueue->department_id, $encounter->current_department_id);
        $this->assertDatabaseHas('queue_entries', [
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $labIntakeQueue->getKey(),
        ]);
    }

    public function test_reception_without_department_and_queue_still_fails_for_entry_type_that_requires_triage(): void
    {
        [$unit, $user, $patient] = $this->context();
        $payload = [
            'idempotency_key' => (string) Str::ulid(),
            'patient_public_id' => $patient->public_id,
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'entry_reason' => 'Emergência sem fila selecionada',
            'administrative_priority' => 'none',
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($user)->withSession($session)
            ->post(route('reception.store'), $payload)
            ->assertSessionHasErrors('queue_id');
        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_ajax_submission_from_the_modal_receives_a_json_redirect_instead_of_a_302(): void
    {
        [$unit, $user, $patient] = $this->context();
        $payload = $this->payload($patient);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $response = $this->actingAs($user)->withSession($session)
            ->postJson(route('reception.store'), $payload);

        $encounter = Encounter::query()->sole();
        $response->assertOk()
            ->assertJson(['redirect' => route('reception.receipt', $encounter)]);
    }

    public function test_ajax_submission_validation_failure_returns_json_errors_the_modal_can_display(): void
    {
        [$unit, $user, $patient] = $this->context();
        $payload = [
            'idempotency_key' => (string) Str::ulid(),
            'patient_public_id' => $patient->public_id,
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'entry_reason' => 'Emergência sem fila selecionada via AJAX',
            'administrative_priority' => 'none',
        ];
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($user)->withSession($session)
            ->postJson(route('reception.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('queue_id');
        $this->assertDatabaseCount('encounters', 0);
    }

    public function test_reception_create_modal_variant_renders_only_the_wizard_fragment(): void
    {
        [$unit, $receptionist, $patient] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];

        $full = $this->actingAs($receptionist)->withSession($session)
            ->get(route('reception.create', ['patient' => $patient->public_id]))
            ->assertOk();
        $full->assertSee('<html lang="pt-BR">', false);
        $full->assertSee('Abrir atendimento');

        $modal = $this->actingAs($receptionist)->withSession($session)
            ->get(route('reception.create', ['patient' => $patient->public_id, 'modal' => 1, 'request_exams' => 1]))
            ->assertOk();
        $modal->assertDontSee('<html lang="pt-BR">', false);
        $modal->assertSee('Registrar requisição de exames laboratoriais');
        $modal->assertSee($patient->displayName());
    }

    public function test_reception_create_modal_fetch_survives_the_ajax_header_axios_sends_by_default(): void
    {
        // O axios manda X-Requested-With: XMLHttpRequest por padrao (bootstrap.js),
        // o que faz Request::expectsJson() virar true e EnsureActiveHealthUnit pular
        // o View::share de $activeHealthUnit - quebrando esta view em produção
        // (ErrorException: Undefined variable $activeHealthUnit). Duas defesas:
        // o front-end manda Accept: text/html nesta chamada, e o fragmento tem um
        // fallback para request()->attributes->get('active_health_unit'). Este
        // teste reproduz o cabeçalho real do axios e confirma as duas.
        [$unit, $receptionist, $patient] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $url = route('reception.create', ['patient' => $patient->public_id, 'modal' => 1, 'request_exams' => 1]);

        $this->actingAs($receptionist)->withSession($session)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get($url)
            ->assertOk()
            ->assertSee('Registrar requisição de exames laboratoriais');

        $this->actingAs($receptionist)->withSession($session)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'])
            ->get($url)
            ->assertOk()
            ->assertSee('Registrar requisição de exames laboratoriais');
    }

    public function test_laboratory_orders_index_shows_the_new_exam_entry_button_for_receptionist(): void
    {
        [$unit, $receptionist] = $this->context();

        $this->actingAs($receptionist)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.orders.index'))
            ->assertOk()
            ->assertSee('Nova entrada com exames')
            ->assertSee('openFor(', false);
    }

    public function test_disabled_catalog_exam_cannot_be_requested_by_direct_reception_post(): void
    {
        [$unit, $receptionist, $patient] = $this->context();
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
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
        $exam = $integration->exams()->create([
            'external_code' => 'DISABLED-127',
            'name' => 'Exame desabilitado',
        ]);
        $this->setLaboratoryExamAvailability($unit, $integration, $exam, false);
        $payload = [
            ...$this->payload($patient),
            'request_exams' => true,
            'exam_requester_id' => $doctor->getKey(),
            'exam_priority' => 'routine',
            'exam_clinical_indication' => 'Tentativa direta com exame desabilitado.',
            'exam_ids' => [$exam->getKey()],
        ];

        $this->actingAs($receptionist)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->postJson(route('reception.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('exam_ids');

        $this->assertDatabaseCount('encounters', 0);
        $this->assertDatabaseCount('exam_orders', 0);
        $this->assertDatabaseCount('exam_order_items', 0);
        $this->assertDatabaseCount('laboratory_order_transmissions', 0);
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

    private function setLaboratoryExamAvailability(
        HealthUnit $unit,
        LaboratoryIntegration $integration,
        LaboratoryExam $laboratoryExam,
        bool $enabled = true,
    ): void {
        $exam = Exam::query()->create([
            'organization_id' => $unit->organization_id,
            'name' => $laboratoryExam->name,
            'sus_procedure_code' => $laboratoryExam->sus_procedure_code,
        ]);
        ExamMapping::query()->create([
            'exam_id' => $exam->getKey(),
            'laboratory_integration_id' => $integration->getKey(),
            'external_code' => $laboratoryExam->external_code,
            'external_name_snapshot' => $laboratoryExam->name,
            'match_type' => ExamMappingMatchType::Exact,
            'mapped_at' => now(),
        ]);
        HealthUnitExam::query()->create([
            'exam_id' => $exam->getKey(),
            'health_unit_id' => $unit->getKey(),
            'is_enabled' => $enabled,
            'enabled_at' => $enabled ? now() : null,
        ]);
    }

    /** @return array{0: HealthUnit, 1: mixed, 2: Patient} */
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
