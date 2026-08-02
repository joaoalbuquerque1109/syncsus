<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Medical\Infrastructure\Eloquent\ClinicalNote;
use App\Modules\Medical\Infrastructure\Eloquent\Diagnosis;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Medical\Infrastructure\Eloquent\Prescription;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\MedicalCatalogSeeder;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClinicalCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_can_void_clinical_records_without_deleting_original_content(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class, MedicalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient = Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P-CORRECTION-1',
            'full_name' => 'Paciente Correcao',
            'normalized_name' => 'PACIENTE CORRECAO',
            'birth_date' => '1980-01-01',
            'sex' => PatientSex::Male,
            'status' => PatientStatus::Active,
            'reference_health_unit_id' => $unit->getKey(),
            'created_by' => $doctor->getKey(),
        ]);
        $queue = Queue::query()->whereBelongsTo($unit)->where('code', 'QUEUE-CLINIC')->sole();
        $encounter = Encounter::query()->create([
            'encounter_number' => 'CORRECTION-0001',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('code', 'RETURN')->value('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('code', 'WALK_IN')->value('id'),
            'current_status' => EncounterStatus::InMedicalCare,
            'administrative_priority' => AdministrativePriority::None,
            'arrival_at' => now(),
            'registration_at' => now(),
            'current_department_id' => $queue->department_id,
            'created_by' => $doctor->getKey(),
        ]);
        $entry = $encounter->queueEntries()->create([
            'queue_id' => $queue->getKey(),
            'ticket_number' => 'M001',
            'status' => 'in_service',
            'entered_at' => now(),
            'assigned_user_id' => $doctor->getKey(),
        ]);
        $consultation = MedicalConsultation::query()->create([
            'encounter_id' => $encounter->getKey(),
            'queue_entry_id' => $entry->getKey(),
            'professional_id' => $doctor->getKey(),
            'specialty_id' => $queue->specialty_id,
            'status' => 'finalized',
            'started_at' => now(),
            'finalized_at' => now(),
            'finalized_by' => $doctor->getKey(),
        ]);
        $diagnosis = Diagnosis::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'description' => 'Hipotese incorreta preservada',
            'diagnosis_type' => 'hypothesis',
            'status' => 'active',
            'diagnosed_by' => $doctor->getKey(),
            'diagnosed_at' => now(),
        ]);
        $prescription = Prescription::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'professional_id' => $doctor->getKey(),
            'prescription_type' => 'home',
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        $note = ClinicalNote::query()->create([
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => $consultation->getKey(),
            'author_id' => $doctor->getKey(),
            'note_type' => 'medical_evolution',
            'content' => 'Conteudo original imutavel',
            'clinical_at' => now(),
            'status' => 'finalized',
            'finalized_at' => now(),
        ]);
        $session = ['active_health_unit_id' => $unit->getKey()];
        $payload = ['reason' => 'Registro lancado no prontuario incorreto.', 'confirmation' => true];

        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.diagnoses.void', [$consultation, $diagnosis]), $payload)->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.prescriptions.cancel', [$consultation, $prescription]), $payload)->assertRedirect();
        $this->actingAs($doctor)->withSession($session)
            ->post(route('medical.clinical-notes.void', [$consultation, $note]), $payload)->assertRedirect();

        $this->assertDatabaseHas('diagnoses', [
            'id' => $diagnosis->getKey(), 'description' => 'Hipotese incorreta preservada',
            'status' => 'voided', 'voided_by' => $doctor->getKey(),
        ]);
        $this->assertDatabaseHas('prescriptions', [
            'id' => $prescription->getKey(), 'status' => 'cancelled', 'cancelled_by' => $doctor->getKey(),
        ]);
        $this->assertDatabaseHas('clinical_notes', [
            'id' => $note->getKey(), 'content' => 'Conteudo original imutavel',
            'status' => 'voided', 'voided_by' => $doctor->getKey(),
        ]);
        $this->assertDatabaseCount('audit_logs', 3);
    }
}
