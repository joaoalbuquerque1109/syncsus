<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\MedicalCatalogSeeder;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MedicalConsultationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_administrator_starts_medical_care_without_check_in(): void
    {
        [$unit, , $entry] = $this->context();
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->postJson(route('medical.start', $entry), ['version' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'Atendimento médico iniciado.');

        $this->assertDatabaseHas('medical_consultations', [
            'queue_entry_id' => $entry->getKey(),
            'professional_id' => $administrator->getKey(),
            'status' => 'draft',
        ]);
        $this->assertDatabaseMissing('medical_shift_attendances', [
            'user_id' => $administrator->getKey(),
            'health_unit_id' => $unit->getKey(),
        ]);
    }

    public function test_only_one_doctor_starts_called_patient_and_record_is_protected(): void
    {
        [$unit, $doctor, $entry] = $this->context();
        $otherDoctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $otherDoctor->assignRole('doctor');
        $this->checkInDoctor($otherDoctor, $unit);
        $receptionist = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $receptionist->assignRole('receptionist');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($receptionist)->withSession($session)
            ->postJson(route('medical.start', $entry), ['version' => 1])
            ->assertForbidden();

        $response = $this->actingAs($doctor)->withSession($session)
            ->postJson(route('medical.start', $entry), ['version' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'Atendimento médico iniciado.');
        $this->actingAs($otherDoctor)->withSession($session)
            ->postJson(route('medical.start', $entry), ['version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $consultation = MedicalConsultation::query()->sole();
        $this->assertStringContainsString($consultation->public_id, (string) $response->json('redirect_url'));
        $this->actingAs($doctor)->withSession($session)
            ->get(route('medical.show', $consultation))
            ->assertOk()
            ->assertSee('Atendimento médico')
            ->assertSee('Paciente do Atendimento')
            ->assertSee('Prescrição')
            ->assertSee('Destinação');
        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'status' => 'in_service',
            'assigned_user_id' => $doctor->getKey(),
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('encounters', [
            'id' => $entry->encounter_id,
            'current_status' => 'in_medical_care',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.consultation_started']);

        $otherUnit = $this->createHealthUnit('NORTH');
        $doctor->healthUnits()->attach($otherUnit);
        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $otherUnit->getKey()])
            ->put(route('medical.draft', $consultation), [
                'version' => 1,
                'chief_complaint' => 'Tentativa entre unidades.',
            ])
            ->assertNotFound();
        $this->assertNull($consultation->fresh()?->chief_complaint);
    }

    public function test_doctor_records_versioned_clinical_components_without_manual_exam_results(): void
    {
        [$unit, $doctor, $entry] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $consultation = $this->start($unit, $doctor, $entry);

        $this->saveRequiredDraft($unit, $doctor, $consultation, 1);

        $code = DiagnosisCode::query()->where('code', 'S80.0')->sole();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.diagnoses', $consultation), [
                'version' => 2,
                'diagnosis_code_id' => $code->getKey(),
                'diagnosis_type' => 'hypothesis',
                'is_primary' => true,
                'notes' => 'Correlacionar com radiografia.',
            ])
            ->assertRedirect();

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.prescriptions', $consultation), [
                'version' => 3,
                'prescription_type' => 'hospital',
                'general_instructions' => 'Observar resposta clínica.',
                'items' => [[
                    'medication_name' => 'Paracetamol',
                    'presentation' => 'Comprimido',
                    'concentration' => '500 mg',
                    'dose' => 500,
                    'dose_unit' => 'mg',
                    'route' => 'Oral',
                    'frequency' => 'A cada 6 horas',
                    'is_as_needed' => true,
                    'as_needed_condition' => 'Se dor.',
                ]],
            ])
            ->assertRedirect();

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.exam-orders', $consultation), [
                'version' => 4,
                'priority' => 'urgent',
                'clinical_indication' => 'Dor e edema após trauma no joelho.',
                'items' => [[
                    'internal_code' => 'RX-JOELHO',
                    'exam_name' => 'Radiografia de joelho',
                    'group' => 'imaging',
                    'laterality' => 'right',
                ]],
            ])
            ->assertRedirect();

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.clinical-notes', $consultation), [
                'version' => 5,
                'note_type' => 'reassessment',
                'content' => 'Paciente reavaliado, com melhora da dor após analgesia.',
            ])
            ->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.referrals', $consultation), [
                'version' => 6,
                'referral_type' => 'internal',
                'destination' => 'Ambulatório de ortopedia',
                'reason' => 'Seguimento do trauma.',
                'clinical_summary' => 'Trauma de joelho sem fratura na radiografia.',
                'priority' => 'routine',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('diagnoses', ['code' => 'S80.0', 'is_primary' => true]);
        $this->assertDatabaseHas('prescriptions', ['status' => 'finalized', 'prescription_type' => 'hospital']);
        $this->assertDatabaseHas('prescription_items', ['medication_name' => 'Paracetamol', 'route' => 'Oral']);
        $this->assertDatabaseHas('exam_order_items', ['exam_name' => 'Radiografia de joelho', 'status' => 'requested']);
        $this->assertDatabaseCount('exam_results', 0);
        $this->assertDatabaseHas('clinical_notes', ['status' => 'finalized', 'note_type' => 'reassessment']);
        $this->assertDatabaseHas('referrals', ['destination' => 'Ambulatório de ortopedia', 'status' => 'issued']);
        $this->assertSame(7, $consultation->fresh()?->version());
    }

    /**
     * @param  array<string, string>  $extra
     */
    #[DataProvider('destinationProvider')]
    public function test_finalization_supports_each_destination_and_preserves_original(
        string $destinationType,
        string $expectedStatus,
        bool $isFinal,
        array $extra,
    ): void {
        [$unit, $doctor, $entry] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $consultation = $this->start($unit, $doctor, $entry);
        $this->saveRequiredDraft($unit, $doctor, $consultation, 1);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.diagnoses', $consultation), [
                'version' => 2,
                'description' => 'Síndrome clínica em investigação',
                'diagnosis_type' => 'hypothesis',
                'is_primary' => true,
            ])
            ->assertRedirect();

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.complete', $consultation), [
                'version' => 3,
                'destination_type' => $destinationType,
                'reason' => 'Destinação definida após avaliação médica.',
                'clinical_condition' => 'Paciente avaliado e clinicamente estável no momento.',
                'professional_confirmation' => true,
                ...$extra,
            ])
            ->assertRedirect(route('medical.show', $consultation));

        $consultation->refresh();
        $this->assertSame('finalized', $consultation->status->value);
        $this->assertNotNull($consultation->content_hash);
        $this->assertDatabaseHas('encounter_destinations', [
            'medical_consultation_id' => $consultation->getKey(),
            'destination_type' => $destinationType,
        ]);
        $this->assertDatabaseHas('encounters', [
            'id' => $entry->encounter_id,
            'current_status' => $expectedStatus,
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'status' => 'completed',
        ]);
        $this->assertSame($isFinal, $entry->encounter->fresh()?->closed_at !== null);

        $originalComplaint = $consultation->chief_complaint;
        $this->actingAs($doctor)->withSession($session)
            ->put(route('medical.draft', $consultation), [
                'version' => $consultation->version(),
                'chief_complaint' => 'Tentativa de alteração silenciosa.',
            ])
            ->assertSessionHasErrors('status');
        $this->assertSame($originalComplaint, $consultation->fresh()?->chief_complaint);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.addendum', $consultation), [
                'reason' => 'Complemento posterior',
                'content' => 'Informação complementar registrada após a finalização.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('medical_addenda', [
            'medical_consultation_id' => $consultation->getKey(),
            'reason' => 'Complemento posterior',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.consultation_completed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'medical.addendum_created']);
    }

    /** @return array<string, array{string, string, bool, array<string, string>}> */
    public static function destinationProvider(): array
    {
        return [
            'alta' => ['discharge', 'discharged', true, [
                'instructions' => 'Repouso relativo, hidratação e uso das medicações conforme prescrição.',
                'warning_signs' => 'Retornar em caso de piora da dor, febre ou falta de ar.',
            ]],
            'observação' => ['observation', 'under_observation', false, [
                'destination_department' => 'Sala de observação adulta',
            ]],
            'internação solicitada' => ['admission_request', 'awaiting_admission', false, [
                'destination_department' => 'Clínica médica',
                'bed_type' => 'Enfermaria',
            ]],
            'transferência' => ['transfer', 'transferred', true, [
                'destination_department' => 'Ortopedia',
                'destination_institution' => 'Hospital de referência',
                'destination_city' => 'Fortaleza',
                'transport_method' => 'Ambulância',
            ]],
            'evasão' => ['evasion', 'left_without_notice', true, [
                'last_known_location' => 'Sala de espera',
                'contact_attempts' => 'Três chamadas no painel e uma tentativa telefônica.',
            ]],
            'óbito' => ['death', 'deceased', true, [
                'death_cause' => 'Causa clínica registrada para posterior homologação documental.',
            ]],
        ];
    }

    private function start(HealthUnit $unit, User $doctor, QueueEntry $entry): MedicalConsultation
    {
        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->postJson(route('medical.start', $entry), ['version' => 1])
            ->assertOk();

        return MedicalConsultation::query()->sole();
    }

    private function saveRequiredDraft(
        HealthUnit $unit,
        User $doctor,
        MedicalConsultation $consultation,
        int $version,
    ): void {
        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->put(route('medical.draft', $consultation), [
                'version' => $version,
                'chief_complaint' => 'Dor no joelho direito após queda.',
                'present_illness_history' => 'Dor iniciada após queda da própria altura, com limitação funcional.',
                'conduct_summary' => 'Analgesia, avaliação radiológica e orientação clínica.',
                'general_state' => 'Bom estado geral, corado e hidratado.',
                'musculoskeletal' => 'Dor à palpação do joelho direito, sem deformidade.',
            ])
            ->assertRedirect();
    }

    /** @return array{HealthUnit, User, QueueEntry, ServicePoint, Patient} */
    private function context(): array
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class, MedicalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->checkInDoctor($doctor, $unit);
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000055',
            'full_name' => 'Paciente do Atendimento',
            'normalized_name' => 'PACIENTE DO ATENDIMENTO',
            'birth_date' => '1984-02-10',
            'sex' => PatientSex::Male,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $doctor->getKey(),
            'updated_by' => $doctor->getKey(),
        ]);
        $queue = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-CLINIC')
            ->sole();
        $point = $queue->servicePoints()->sole();
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0055',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'risk_level_id' => RiskLevel::query()->where('code', 'YELLOW')->value('id'),
            'current_status' => EncounterStatus::CalledToMedical,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(40),
            'registration_at' => now()->subMinutes(40),
            'triage_finished_at' => now()->subMinutes(20),
            'current_department_id' => $queue->department_id,
            'created_by' => $doctor->getKey(),
        ]);
        $entry = QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'C055',
            'priority_weight' => 60,
            'status' => QueueEntryStatus::Called,
            'entered_at' => now()->subMinutes(20),
            'first_called_at' => now()->subMinute(),
            'last_called_at' => now()->subMinute(),
            'service_point_id' => $point->getKey(),
            'call_count' => 1,
            'lock_version' => 1,
        ]);

        return [$unit, $doctor, $entry, $point, $patient];
    }
}
