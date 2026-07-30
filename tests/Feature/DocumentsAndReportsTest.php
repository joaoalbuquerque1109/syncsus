<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Documents\Infrastructure\Eloquent\ClinicalDocument;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\DestinationType;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
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
use App\Modules\Reports\Application\Queries\OperationalDashboardQuery;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DocumentsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_pdf_is_private_versioned_verifiable_and_voidable(): void
    {
        Storage::fake('local_private');
        [$unit, $doctor, , $receptionist, $consultation] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.documents', $consultation), [
                'document_type' => 'medical_report',
                'title' => 'Relatório clínico de alta',
                'body' => 'Paciente avaliado, tratado e liberado em condições clínicas estáveis.',
                'recipient_name' => 'Unidade de referência',
                'additional_information' => 'Manter acompanhamento na atenção primária.',
            ])
            ->assertRedirect();

        $document = ClinicalDocument::query()->with('currentVersion')->sole();
        $firstVersion = $document->currentVersion;
        $this->assertNotNull($firstVersion);
        Storage::disk('local_private')->assertExists($firstVersion->rendered_html_path);
        Storage::disk('local_private')->assertExists($firstVersion->pdf_path);
        $firstPdf = Storage::disk('local_private')->get($firstVersion->pdf_path);
        $this->assertStringStartsWith('%PDF', $firstPdf);
        $this->assertSame(hash('sha256', $firstPdf), $firstVersion->file_hash);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.issued']);

        $this->get(route('documents.verify', $document->verification_code))
            ->assertOk()
            ->assertSee('Relatório clínico de alta')
            ->assertDontSee('Ana Beatriz dos Santos');

        $download = $this->actingAs($doctor)->withSession($session)
            ->get(route('documents.pdf', $document))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private');
        $this->assertStringStartsWith('%PDF', $download->getFile()->getContent());
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.downloaded']);

        $this->actingAs($receptionist)->withSession($session)
            ->get(route('documents.pdf', $document))
            ->assertForbidden();

        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.versions', $document), [
                'reason' => 'Correção de orientação clínica',
                'body' => 'Paciente avaliado, tratado e liberado com retorno programado em sete dias.',
            ])
            ->assertRedirect();

        $document->refresh()->load(['currentVersion', 'versions']);
        $this->assertCount(2, $document->versions);
        $this->assertSame(2, $document->currentVersion?->version_number);
        $this->assertSame(
            'Unidade de referência',
            $document->currentVersion?->structured_content['recipient_name'] ?? null,
        );
        $this->assertNotSame($firstVersion->file_hash, $document->currentVersion?->file_hash);
        Storage::disk('local_private')->assertExists($firstVersion->pdf_path);

        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.void', $document), [
                'reason' => 'Documento substituído por registro institucional externo.',
            ])
            ->assertRedirect();

        $this->assertSame('voided', $document->fresh()?->status);
        $this->get(route('documents.verify', $document->verification_code))
            ->assertOk()
            ->assertSee('Documento anulado');
        $this->actingAs($doctor)->withSession($session)
            ->post(route('documents.versions', $document), [
                'reason' => 'Alteração posterior indevida',
                'body' => 'Este conteúdo não deve gerar uma nova versão válida.',
            ])
            ->assertSessionHasErrors('status');
        Storage::disk('local_private')->assertExists($firstVersion->pdf_path);
        Storage::disk('local_private')->assertExists($document->currentVersion?->pdf_path ?? '');
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.version_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'document.voided']);
    }

    public function test_dashboard_and_reports_filter_mask_export_audit_and_isolate_units(): void
    {
        [$unit, , $manager, $receptionist, , $encounter] = $this->context();
        $session = ['active_health_unit_id' => $unit->getKey()];
        $this->createActiveEncounter($unit, $manager);
        $otherUnit = $this->createHealthUnit('NORTH');
        $this->createExternalEncounter($otherUnit, $manager);

        $metrics = $this->actingAs($manager)->withSession($session)
            ->getJson(route('dashboard.metrics'))
            ->assertOk()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertJsonPath('data.waiting_triage', 1)
            ->assertJsonPath('data.discharges_today', 1);
        $this->assertNotNull($metrics->json('data.server_time'));

        $this->actingAs($manager)->withSession($session)
            ->getJson(route('dashboard.active-encounters'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.patient', 'A*** B*** d*** S***')
            ->assertJsonPath('data.0.ticket', 'T101');

        $this->actingAs($manager)->withSession($session)
            ->getJson(route('dashboard.state'))
            ->assertOk()
            ->assertJsonPath('data.metrics.waiting_triage', 1)
            ->assertJsonCount(1, 'data.active_encounters')
            ->assertJsonPath('data.active_encounters.0.ticket', 'T101');

        $filters = [
            'date_from' => today()->subDay()->toDateString(),
            'date_to' => today()->toDateString(),
        ];
        $this->actingAs($manager)->withSession($session)
            ->get(route('reports.index', $filters))
            ->assertOk()
            ->assertSee('Relatório de atendimentos')
            ->assertSee('A*** B*** d*** S***')
            ->assertDontSee('Ana Beatriz dos Santos')
            ->assertDontSee('Paciente de Outra Unidade');

        $csv = $this->actingAs($manager)->withSession($session)
            ->get(route('reports.csv', $filters))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $csvContent = $csv->streamedContent();
        $this->assertStringContainsString('A*** B*** d*** S***', $csvContent);
        $this->assertStringNotContainsString('Ana Beatriz dos Santos', $csvContent);
        $this->assertStringNotContainsString('Paciente de Outra Unidade', $csvContent);

        $pdf = $this->actingAs($manager)->withSession($session)
            ->get(route('reports.pdf', [
                ...$filters,
                'status' => $encounter->currentStatusEnum()->value,
                'destination_type' => DestinationType::Discharge->value,
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'no-store, private');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'report.exported',
            'health_unit_id' => $unit->getKey(),
        ]);

        $this->actingAs($receptionist)->withSession($session)
            ->get(route('reports.index', $filters))
            ->assertForbidden();
        $this->actingAs($manager)->withSession($session)
            ->from(route('reports.index'))
            ->get(route('reports.index', [
                'date_from' => today()->subDays(400)->toDateString(),
                'date_to' => today()->toDateString(),
            ]))
            ->assertRedirect(route('reports.index'))
            ->assertSessionHasErrors('date_to');
    }

    public function test_dashboard_metrics_use_two_aggregate_queries_and_the_new_index_exists(): void
    {
        [$unit, , $manager] = $this->context();
        $this->createActiveEncounter($unit, $manager);
        config()->set('sync_sus.performance_cache.enabled', false);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(OperationalDashboardQuery::class)->metrics($unit);
        $queries = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(
                mb_strtolower(ltrim((string) $query['query'])),
                'select',
            ));
        DB::disableQueryLog();

        $this->assertCount(2, $queries);
        $indexes = collect(DB::select("PRAGMA index_list('encounters')"))
            ->pluck('name');
        $this->assertContains('encounters_unit_status_closed_at_index', $indexes);
    }

    /** @return array{HealthUnit, User, User, User, MedicalConsultation, Encounter} */
    private function context(): array
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Dra. Marina Lopes', 'must_change_password' => false]);
        $doctor->assignRole('doctor');
        $this->checkInDoctor($doctor, $unit);
        $manager = $this->createUserWithUnit($unit, ['name' => 'Gestor da Unidade', 'must_change_password' => false]);
        $manager->assignRole('manager');
        $receptionist = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $receptionist->assignRole('receptionist');
        $patient = $this->createPatient($unit, $doctor, 'P00000100', 'Ana Beatriz dos Santos');
        $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-CLINIC')->sole();
        $arrivalAt = now()->subMinutes(90);
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0100',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'risk_level_id' => RiskLevel::query()->where('code', 'YELLOW')->value('id'),
            'current_status' => EncounterStatus::Discharged,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => $arrivalAt,
            'registration_at' => $arrivalAt,
            'triage_started_at' => $arrivalAt->copy()->addMinutes(10),
            'triage_finished_at' => $arrivalAt->copy()->addMinutes(20),
            'medical_started_at' => $arrivalAt->copy()->addMinutes(35),
            'medical_finished_at' => $arrivalAt->copy()->addMinutes(70),
            'closed_at' => $arrivalAt->copy()->addMinutes(75),
            'current_department_id' => $queue->department_id,
            'created_by' => $doctor->getKey(),
        ]);
        $entry = QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'C100',
            'priority_weight' => 60,
            'status' => QueueEntryStatus::Completed,
            'entered_at' => $arrivalAt->copy()->addMinutes(20),
            'service_started_at' => $arrivalAt->copy()->addMinutes(35),
            'exited_at' => $arrivalAt->copy()->addMinutes(70),
            'assigned_user_id' => $doctor->getKey(),
            'lock_version' => 1,
        ]);
        $consultation = MedicalConsultation::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_entry_id' => $entry->getKey(),
            'professional_id' => $doctor->getKey(),
            'status' => MedicalConsultationStatus::Finalized,
            'chief_complaint' => 'Dor de cabeça.',
            'conduct_summary' => 'Tratamento sintomático e alta.',
            'content_hash' => hash('sha256', 'consulta-finalizada'),
            'started_at' => $arrivalAt->copy()->addMinutes(35),
            'finalized_at' => $arrivalAt->copy()->addMinutes(70),
            'finalized_by' => $doctor->getKey(),
        ]);
        EncounterDestination::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'destination_type' => DestinationType::Discharge,
            'reason' => 'Melhora clínica após tratamento.',
            'clinical_condition' => 'Estável.',
            'recorded_by' => $doctor->getKey(),
            'occurred_at' => $arrivalAt->copy()->addMinutes(70),
        ]);

        return [$unit, $doctor, $manager, $receptionist, $consultation, $encounter];
    }

    private function createActiveEncounter(HealthUnit $unit, User $actor): void
    {
        $patient = $this->createPatient($unit, $actor, 'P00000101', 'Ana Beatriz dos Santos');
        $queue = Queue::query()->where('health_unit_id', $unit->getKey())->where('code', 'QUEUE-TRIAGE')->sole();
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CENTRAL-20260724-0101',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::WaitingTriage,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(12),
            'registration_at' => now()->subMinutes(12),
            'current_department_id' => $queue->department_id,
            'created_by' => $actor->getKey(),
        ]);
        QueueEntry::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'T101',
            'priority_weight' => 10,
            'status' => QueueEntryStatus::Waiting,
            'entered_at' => now()->subMinutes(12),
            'lock_version' => 1,
        ]);
    }

    private function createExternalEncounter(HealthUnit $unit, User $actor): void
    {
        $patient = $this->createPatient($unit, $actor, 'P00000999', 'Paciente de Outra Unidade');
        Encounter::query()->create([
            'encounter_number' => 'NORTH-20260724-0999',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'EMERGENCY')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::WaitingTriage,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now()->subMinutes(5),
            'registration_at' => now()->subMinutes(5),
            'created_by' => $actor->getKey(),
        ]);
    }

    private function createPatient(
        HealthUnit $unit,
        User $actor,
        string $record,
        string $name,
    ): Patient {
        return Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => $record,
            'full_name' => $name,
            'normalized_name' => mb_strtoupper($name),
            'birth_date' => '1988-04-12',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $actor->getKey(),
            'updated_by' => $actor->getKey(),
        ]);
    }
}
