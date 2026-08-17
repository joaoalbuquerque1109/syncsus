<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Modules\Triage\Infrastructure\Eloquent\TriageDiscriminator;
use App\Modules\Triage\Infrastructure\Eloquent\TriageFlowchart;
use App\Modules\Triage\Infrastructure\Eloquent\TriageProtocol;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\TriageCatalogSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class TriageFlowTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_only_one_professional_can_start_the_called_triage(): void
    {
        [$unit, $professional, $entry] = $this->context();
        $otherProfessional = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $otherProfessional->assignRole('triage_professional');
        $this->registerTriageProfessional($otherProfessional, $unit);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $response = $this->actingAs($professional)->withSession($session)
            ->postJson(route('triage.start', $entry), ['version' => 1])
            ->assertOk()
            ->assertJsonPath('message', 'Triagem iniciada.');

        $this->actingAs($otherProfessional)->withSession($session)
            ->postJson(route('triage.start', $entry), ['version' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('version');

        $assessment = TriageAssessment::query()->sole();
        $this->assertSame($professional->getKey(), $assessment->professional_id);
        $this->assertStringContainsString($assessment->public_id, (string) $response->json('redirect_url'));
        $this->actingAs($professional)->withSession($session)
            ->get(route('triage.show', $assessment))
            ->assertOk()
            ->assertSee('Paciente da Triagem')
            ->assertSee('Queixa principal')
            ->assertSee('Sinais vitais')
            ->assertSee('Classificação');
        $this->assertDatabaseCount('triage_assessments', 1);
        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'status' => 'in_service',
            'assigned_user_id' => $professional->getKey(),
            'lock_version' => 2,
        ]);
        $this->assertDatabaseHas('encounters', [
            'id' => $entry->encounter_id,
            'current_status' => 'in_triage',
        ]);
        $this->assertDatabaseHas('queue_entry_history', [
            'queue_entry_id' => $entry->getKey(),
            'action' => 'triage_started',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'triage.started']);
    }

    public function test_responsible_professional_and_administrator_can_edit_active_triage_from_queue(): void
    {
        [$unit, $professional, $entry] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $assessment = $this->start($unit, $professional, $entry);
        $otherProfessional = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $otherProfessional->assignRole('triage_professional');
        $this->registerTriageProfessional($otherProfessional, $unit);
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');

        $this->actingAs($professional)->withSession($session)
            ->getJson(route('queues.entries', $entry->queue))
            ->assertOk()
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.edit_url', route('triage.show', $assessment));

        $this->actingAs($otherProfessional)->withSession($session)
            ->getJson(route('queues.entries', $entry->queue))
            ->assertOk()
            ->assertJsonPath('data.0.can_edit', false)
            ->assertJsonPath('data.0.edit_url', null);
        $this->actingAs($otherProfessional)->withSession($session)
            ->get(route('triage.show', $assessment))
            ->assertForbidden();

        $this->actingAs($administrator)->withSession($session)
            ->getJson(route('queues.entries', $entry->queue))
            ->assertOk()
            ->assertJsonPath('data.0.can_edit', true)
            ->assertJsonPath('data.0.edit_url', route('triage.show', $assessment));
        $this->actingAs($administrator)->withSession($session)
            ->get(route('triage.show', $assessment))
            ->assertOk();
        $this->actingAs($administrator)->withSession($session)
            ->put(route('triage.draft', $assessment), [
                'version' => 1,
                'chief_complaint' => 'Triagem revisada pelo administrador global.',
            ])
            ->assertRedirect();

        $this->assertSame('Triagem revisada pelo administrador global.', $assessment->fresh()?->chief_complaint);
    }

    public function test_vital_signs_are_historical_validated_and_never_choose_the_risk(): void
    {
        [$unit, $professional, $entry] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $assessment = $this->start($unit, $professional, $entry);

        $this->actingAs($professional)->withSession($session)
            ->put(route('triage.draft', $assessment), [
                'version' => 1,
                'chief_complaint' => 'Dor abdominal há duas horas.',
                'symptom_onset' => 'Há duas horas',
                'brief_history' => 'Paciente relata início súbito, sem perda de consciência.',
                'pain_scale' => 6,
                'has_reported_allergies' => false,
                'uses_medications' => false,
                'requires_isolation' => false,
                'violence_signs' => false,
            ])
            ->assertRedirect();

        $this->actingAs($professional)->withSession($session)
            ->post(route('triage.vital-signs', $assessment), [
                'version' => 2,
                'systolic_bp' => 120,
                'diastolic_bp' => 80,
                'heart_rate' => 76,
                'temperature_c' => '36.7',
                'oxygen_saturation' => 98,
                'weight_kg' => 72,
                'height' => '1.70',
                'height_unit' => 'm',
                'pain_scale' => 6,
                'clinical_alerts' => ['diabetes'],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('vital_sign_measurements', [
            'triage_assessment_id' => $assessment->getKey(),
            'height_cm' => 170,
            'bmi' => 24.91,
            'range_configuration_version' => '2026.1',
        ]);
        $this->assertDatabaseHas('triage_assessments', [
            'id' => $assessment->getKey(),
            'risk_level_id' => null,
            'lock_version' => 3,
        ]);

        $this->actingAs($professional)->withSession($session)
            ->post(route('triage.vital-signs', $assessment), [
                'version' => 3,
                'temperature_c' => 44,
                'height_unit' => 'cm',
            ])
            ->assertSessionHasErrors('confirm_outside_ranges');
        $this->assertDatabaseCount('vital_sign_measurements', 1);

        $this->actingAs($professional)->withSession($session)
            ->post(route('triage.vital-signs', $assessment), [
                'version' => 3,
                'temperature_c' => 44,
                'height_unit' => 'cm',
                'confirm_outside_ranges' => true,
            ])
            ->assertRedirect();
        $this->assertDatabaseCount('vital_sign_measurements', 2);
        $this->assertDatabaseHas('vital_sign_measurements', [
            'triage_assessment_id' => $assessment->getKey(),
            'temperature_c' => 44,
        ]);

        $this->actingAs($professional)->withSession($session)
            ->post(route('triage.vital-signs', $assessment), [
                'version' => 4,
                'temperature_c' => 51,
                'height_unit' => 'cm',
                'confirm_outside_ranges' => true,
            ])
            ->assertSessionHasErrors('temperature_c');
        $this->assertDatabaseCount('vital_sign_measurements', 2);
        $this->assertNull($assessment->fresh()?->risk_level_id);
    }

    public function test_professional_finalization_routes_patient_and_original_becomes_immutable(): void
    {
        [$unit, $professional, $entry] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $assessment = $this->start($unit, $professional, $entry);

        $this->actingAs($professional)->withSession($session)
            ->put(route('triage.draft', $assessment), [
                'version' => 1,
                'chief_complaint' => 'Falta de ar aos esforços.',
                'brief_history' => 'Sintomas iniciados hoje, com piora progressiva durante a tarde.',
                'has_reported_allergies' => true,
                'reported_allergies' => 'Dipirona: urticária.',
                'uses_medications' => true,
                'current_medications' => 'Losartana 50 mg.',
                'requires_isolation' => false,
                'violence_signs' => false,
            ])
            ->assertRedirect();

        $protocol = TriageProtocol::query()->where('code', 'SYNC-TRIAGE')->sole();
        $flowchart = TriageFlowchart::query()
            ->where('triage_protocol_id', $protocol->getKey())
            ->where('code', 'RESPIRATORY')
            ->sole();
        $discriminator = TriageDiscriminator::query()
            ->where('triage_flowchart_id', $flowchart->getKey())
            ->where('code', 'MODERATE')
            ->sole();
        $risk = RiskLevel::query()->where('code', 'YELLOW')->sole();
        $destination = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-CLINIC')
            ->sole();

        $this->actingAs($professional)->withSession($session)
            ->post(route('triage.complete', $assessment), [
                'version' => 2,
                'triage_protocol_id' => $protocol->getKey(),
                'triage_flowchart_id' => $flowchart->getKey(),
                'triage_discriminator_id' => $discriminator->getKey(),
                'risk_level_id' => $risk->getKey(),
                'risk_justification' => 'Dispneia aos esforços sem instabilidade hemodinâmica.',
                'destination_queue_id' => $destination->getKey(),
                'routing_notes' => 'Manter em observação na sala de espera.',
                'professional_confirmation' => true,
            ])
            ->assertRedirect(route('triage.show', $assessment));

        $assessment->refresh();
        $this->assertSame('finalized', $assessment->status->value);
        $this->assertSame('2026.1', $assessment->protocol_version);
        $this->assertSame($risk->getKey(), $assessment->risk_level_id);
        $this->assertNotNull($assessment->finalized_at);
        $this->assertDatabaseHas('queue_entries', [
            'id' => $entry->getKey(),
            'status' => 'completed',
            'exit_reason' => 'Triagem finalizada',
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'encounter_id' => $entry->encounter_id,
            'queue_id' => $destination->getKey(),
            'ticket_number' => 'C001',
            'priority_weight' => 60,
            'status' => 'waiting',
        ]);
        $this->assertDatabaseHas('encounters', [
            'id' => $entry->encounter_id,
            'current_status' => 'waiting_medical',
            'risk_level_id' => $risk->getKey(),
            'current_department_id' => $destination->department_id,
        ]);
        $this->assertDatabaseHas('patient_allergies', [
            'patient_id' => $entry->encounter->patient_id,
            'source' => 'triage',
            'reaction' => 'Dipirona: urticária.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'triage.completed']);

        $originalComplaint = $assessment->chief_complaint;
        $this->actingAs($professional)->withSession($session)
            ->put(route('triage.draft', $assessment), [
                'version' => $assessment->version(),
                'chief_complaint' => 'Tentativa de alteração.',
            ])
            ->assertSessionHasErrors('status');
        $this->assertSame($originalComplaint, $assessment->fresh()?->chief_complaint);

        $this->actingAs($professional)->withSession($session)
            ->post(route('triage.addendum', $assessment), [
                'reason' => 'Complemento posterior',
                'content' => 'Paciente informou posteriormente alergia também a ibuprofeno.',
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('triage_addenda', [
            'triage_assessment_id' => $assessment->getKey(),
            'reason' => 'Complemento posterior',
        ]);
        $this->assertSame($originalComplaint, $assessment->fresh()?->chief_complaint);
        $this->assertDatabaseHas('audit_logs', ['action' => 'triage.addendum_created']);
    }

    public function test_platform_administrator_can_finalize_triage_assigned_to_another_professional(): void
    {
        [$unit, $professional, $entry] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $assessment = $this->start($unit, $professional, $entry);

        $this->actingAs($professional)->withSession($session)
            ->put(route('triage.draft', $assessment), [
                'version' => 1,
                'chief_complaint' => 'Paciente com mal-estar e tontura.',
                'brief_history' => 'Sintomas iniciados hoje, sem perda de consciência.',
                'has_reported_allergies' => false,
                'uses_medications' => false,
                'requires_isolation' => false,
                'violence_signs' => false,
            ])
            ->assertRedirect();

        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $protocol = TriageProtocol::query()->where('code', 'SYNC-TRIAGE')->sole();
        $flowchart = TriageFlowchart::query()
            ->where('triage_protocol_id', $protocol->getKey())
            ->where('code', 'RESPIRATORY')
            ->sole();
        $discriminator = TriageDiscriminator::query()
            ->where('triage_flowchart_id', $flowchart->getKey())
            ->where('code', 'MODERATE')
            ->sole();
        $risk = RiskLevel::query()->where('code', 'YELLOW')->sole();
        $destination = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-CLINIC')
            ->sole();

        $this->actingAs($administrator)->withSession($session)
            ->post(route('triage.complete', $assessment), [
                'version' => 2,
                'triage_protocol_id' => $protocol->getKey(),
                'triage_flowchart_id' => $flowchart->getKey(),
                'triage_discriminator_id' => $discriminator->getKey(),
                'risk_level_id' => $risk->getKey(),
                'risk_justification' => 'Administrador confirmou a classificação registrada.',
                'destination_queue_id' => $destination->getKey(),
                'professional_confirmation' => true,
            ])
            ->assertRedirect(route('triage.show', $assessment));

        $this->assertDatabaseHas('triage_assessments', [
            'id' => $assessment->getKey(),
            'status' => 'finalized',
            'professional_id' => $professional->getKey(),
            'finalized_by' => $administrator->getKey(),
        ]);
        $this->assertDatabaseHas('queue_entries', [
            'encounter_id' => $entry->encounter_id,
            'queue_id' => $destination->getKey(),
            'status' => 'waiting',
        ]);
    }

    public function test_triage_permissions_protect_start_and_clinical_record(): void
    {
        [$unit, $professional, $entry] = $this->context();
        $receptionist = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $receptionist->assignRole('receptionist');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($receptionist)->withSession($session)
            ->postJson(route('triage.start', $entry), ['version' => 1])
            ->assertForbidden();
        $this->assertDatabaseCount('triage_assessments', 0);

        $assessment = $this->start($unit, $professional, $entry);
        $otherUnit = $this->createHealthUnit('NORTH');
        $professional->healthUnits()->attach($otherUnit);

        $this->actingAs($professional)
            ->withSession(['active_health_unit_id' => $otherUnit->getKey()])
            ->put(route('triage.draft', $assessment), [
                'version' => 1,
                'chief_complaint' => 'Tentativa entre unidades.',
            ])
            ->assertNotFound();
        $this->assertNull($assessment->fresh()?->chief_complaint);
    }

    private function start(HealthUnit $unit, User $professional, QueueEntry $entry): TriageAssessment
    {
        $this->actingAs($professional)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->postJson(route('triage.start', $entry), ['version' => 1])
            ->assertOk();

        return TriageAssessment::query()->sole();
    }

    /** @return array{HealthUnit, User, QueueEntry, ServicePoint, Patient} */
    private function context(): array
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class, TriageCatalogSeeder::class]);
        $professional = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $professional->assignRole('triage_professional');
        $this->registerTriageProfessional($professional, $unit);
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000044',
            'full_name' => 'Paciente da Triagem',
            'normalized_name' => 'PACIENTE DA TRIAGEM',
            'birth_date' => '1989-06-15',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $professional->getKey(),
            'updated_by' => $professional->getKey(),
        ]);
        $queue = Queue::query()
            ->where('health_unit_id', $unit->getKey())
            ->where('code', 'QUEUE-TRIAGE')
            ->sole();
        $point = $queue->servicePoints()->sole();
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0044',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::CalledToTriage,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(20),
            'registration_at' => now()->subMinutes(20),
            'current_department_id' => $queue->department_id,
            'created_by' => $professional->getKey(),
        ]);
        $entry = QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'T044',
            'priority_weight' => 10,
            'status' => QueueEntryStatus::Called,
            'entered_at' => now()->subMinutes(20),
            'first_called_at' => now()->subMinute(),
            'last_called_at' => now()->subMinute(),
            'service_point_id' => $point->getKey(),
            'call_count' => 1,
            'lock_version' => 1,
        ]);

        return [$unit, $professional, $entry, $point, $patient];
    }
}
