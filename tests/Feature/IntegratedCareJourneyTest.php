<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\Department;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Modules\Triage\Infrastructure\Eloquent\TriageDiscriminator;
use App\Modules\Triage\Infrastructure\Eloquent\TriageFlowchart;
use App\Modules\Triage\Infrastructure\Eloquent\TriageProtocol;
use Database\Seeders\MedicalCatalogSeeder;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TriageCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class IntegratedCareJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fictitious_patient_completes_reception_triage_medical_discharge_and_document_flow(): void
    {
        Storage::fake('local_private');
        $unit = $this->createHealthUnit();
        $this->seed([
            RolePermissionSeeder::class,
            OperationalCatalogSeeder::class,
            TriageCatalogSeeder::class,
            MedicalCatalogSeeder::class,
        ]);
        $receptionist = $this->createUserWithUnit($unit, ['name' => 'Recepcionista Teste', 'must_change_password' => false]);
        $receptionist->assignRole('receptionist');
        $triageProfessional = $this->createUserWithUnit($unit, ['name' => 'Enfermeira Teste', 'must_change_password' => false]);
        $triageProfessional->assignRole('triage_professional');
        $this->registerTriageProfessional($triageProfessional, $unit);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Médico Teste', 'must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000888',
            'full_name' => 'Paciente Sintético da Homologação',
            'normalized_name' => 'PACIENTE SINTETICO DA HOMOLOGACAO',
            'birth_date' => '1985-08-12',
            'sex' => PatientSex::Male,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $receptionist->getKey(),
            'updated_by' => $receptionist->getKey(),
        ]);
        $session = ['active_health_unit_id' => $unit->getKey()];
        $triageDepartment = Department::query()->where('code', 'TRIAGE')->sole();
        $triageQueue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();

        $this->actingAs($receptionist)->withSession($session)
            ->post(route('reception.store'), [
                'idempotency_key' => (string) Str::ulid(),
                'patient_public_id' => $patient->public_id,
                'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
                'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
                'entry_reason' => 'Dor abdominal moderada iniciada há duas horas.',
                'administrative_priority' => 'none',
                'department_id' => $triageDepartment->getKey(),
                'queue_id' => $triageQueue->getKey(),
            ])
            ->assertRedirect();

        $encounter = Encounter::query()->sole();
        $triageEntry = QueueEntry::query()->where('encounter_id', $encounter->getKey())->sole();
        $triagePoint = $triageQueue->servicePoints()->sole();
        $this->actingAs($triageProfessional)->withSession($session)
            ->postJson(route('queue-entries.call', $triageEntry), [
                'version' => 1,
                'service_point' => $triagePoint->public_id,
            ])
            ->assertOk()
            ->assertJsonPath('entry.status', 'called');
        $this->actingAs($triageProfessional)->withSession($session)
            ->postJson(route('triage.start', $triageEntry), ['version' => 2])
            ->assertOk();

        $triage = TriageAssessment::query()->sole();
        $this->actingAs($triageProfessional)->withSession($session)
            ->get(route('triage.show', $triage))
            ->assertOk()
            ->assertSee('Paciente Sintético da Homologação');
        $this->actingAs($triageProfessional)->withSession($session)
            ->put(route('triage.draft', $triage), [
                'version' => 1,
                'chief_complaint' => 'Dor abdominal moderada.',
                'brief_history' => 'Início há duas horas, sem perda de consciência ou sangramento.',
                'pain_scale' => 6,
                'has_reported_allergies' => false,
                'uses_medications' => false,
                'requires_isolation' => false,
                'violence_signs' => false,
            ])
            ->assertRedirect();
        $this->actingAs($triageProfessional)->withSession($session)
            ->post(route('triage.vital-signs', $triage), [
                'version' => 2,
                'systolic_bp' => 120,
                'diastolic_bp' => 80,
                'heart_rate' => 78,
                'temperature_c' => '36.8',
                'oxygen_saturation' => 98,
                'height_unit' => 'cm',
                'pain_scale' => 6,
            ])
            ->assertRedirect();

        $protocol = TriageProtocol::query()->where('code', 'SYNC-TRIAGE')->sole();
        $flowchart = TriageFlowchart::query()
            ->where('triage_protocol_id', $protocol->getKey())
            ->where('code', 'PAIN')
            ->sole();
        $discriminator = TriageDiscriminator::query()
            ->where('triage_flowchart_id', $flowchart->getKey())
            ->orderBy('display_order')
            ->firstOrFail();
        $risk = RiskLevel::query()->where('code', 'YELLOW')->sole();
        $medicalQueue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-CLINIC')->sole();
        $this->actingAs($triageProfessional)->withSession($session)
            ->post(route('triage.complete', $triage), [
                'version' => 3,
                'triage_protocol_id' => $protocol->getKey(),
                'triage_flowchart_id' => $flowchart->getKey(),
                'triage_discriminator_id' => $discriminator->getKey(),
                'risk_level_id' => $risk->getKey(),
                'risk_justification' => 'Dor moderada persistente sem sinais atuais de instabilidade.',
                'destination_queue_id' => $medicalQueue->getKey(),
                'routing_notes' => 'Aguardar avaliação médica.',
                'professional_confirmation' => true,
            ])
            ->assertRedirect();

        $medicalEntry = QueueEntry::query()
            ->where('encounter_id', $encounter->getKey())
            ->where('queue_id', $medicalQueue->getKey())
            ->sole();
        $medicalPoint = $medicalQueue->servicePoints()->sole();
        $this->actingAs($doctor)->withSession($session)
            ->postJson(route('queue-entries.call', $medicalEntry), [
                'version' => 1,
                'service_point' => $medicalPoint->public_id,
            ])
            ->assertOk();
        $this->actingAs($doctor)->withSession($session)
            ->postJson(route('medical.start', $medicalEntry), ['version' => 2])
            ->assertOk();

        $consultation = MedicalConsultation::query()->sole();
        $this->actingAs($doctor)->withSession($session)
            ->get(route('medical.show', $consultation))
            ->assertOk()
            ->assertSee('Paciente Sintético da Homologação');
        $this->actingAs($doctor)->withSession($session)
            ->put(route('medical.draft', $consultation), [
                'version' => 1,
                'chief_complaint' => 'Dor abdominal moderada.',
                'present_illness_history' => 'Dor iniciada há duas horas, com melhora após analgesia.',
                'conduct_summary' => 'Analgesia, observação breve e orientações de retorno.',
                'general_state' => 'Bom estado geral, consciente e orientado.',
                'abdomen' => 'Abdome flácido, sem sinais de irritação peritoneal.',
            ])
            ->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.diagnoses', $consultation), [
                'version' => 2,
                'description' => 'Dor abdominal inespecífica em melhora',
                'diagnosis_type' => 'hypothesis',
                'is_primary' => true,
            ])
            ->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.complete', $consultation), [
                'version' => 3,
                'destination_type' => 'discharge',
                'reason' => 'Melhora clínica e ausência de sinais de alarme.',
                'clinical_condition' => 'Paciente estável, consciente e orientado.',
                'instructions' => 'Manter hidratação e alimentação leve nas próximas horas.',
                'warning_signs' => 'Retornar imediatamente em caso de febre, vômitos persistentes ou piora da dor.',
                'follow_up' => 'Unidade de atenção primária em até sete dias.',
                'professional_confirmation' => true,
            ])
            ->assertRedirect(route('medical.show', $consultation));
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.documents', $consultation), [
                'document_type' => 'discharge_guidance',
                'title' => 'Orientações de alta da homologação',
                'body' => 'Paciente recebeu orientações de hidratação, alimentação e sinais para retorno imediato.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('encounters', [
            'id' => $encounter->getKey(),
            'current_status' => 'discharged',
        ]);
        $this->assertDatabaseHas('triage_assessments', ['status' => 'finalized']);
        $this->assertDatabaseHas('medical_consultations', ['status' => 'finalized']);
        $this->assertDatabaseHas('encounter_destinations', ['destination_type' => 'discharge']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'encounter.opened']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'triage.completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.consultation_completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.issued']);
        $this->assertDatabaseHas('patient_access_logs', ['access_type' => 'triage_record_view']);
        $this->assertDatabaseHas('patient_access_logs', ['access_type' => 'medical_record_view']);
        $this->assertNotNull($consultation->fresh()?->content_hash);
    }
}
