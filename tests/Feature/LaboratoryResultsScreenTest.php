<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Domain\Enums\PatientStatus;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Database\Seeders\OperationalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class LaboratoryResultsScreenTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_doctor_only_sees_exam_orders_they_personally_requested(): void
    {
        $unit = $this->createHealthUnit('RESULTS-DOCTOR');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctorA = $this->createUserWithUnit($unit, ['name' => 'Doutora A']);
        $doctorA->assignRole('doctor');
        $this->registerDoctor($doctorA, $unit);
        $doctorB = $this->createUserWithUnit($unit, ['name' => 'Doutor B']);
        $doctorB->assignRole('doctor');
        $this->registerDoctor($doctorB, $unit);
        $patientA = $this->createPatient($unit, 'PACIENTE DA DOUTORA A', $doctorA);
        $patientB = $this->createPatient($unit, 'PACIENTE DO DOUTOR B', $doctorB);
        $this->createOrderWithItem($unit, $patientA, $doctorA, 'medical');
        $this->createOrderWithItem($unit, $patientB, $doctorB, 'medical');

        $response = $this->actingAs($doctorA)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.results.index'));

        $response->assertOk();
        $response->assertSee('PACIENTE DA DOUTORA A');
        $response->assertDontSee('PACIENTE DO DOUTOR B');
    }

    public function test_administrator_sees_every_order_in_the_unit(): void
    {
        $unit = $this->createHealthUnit('RESULTS-ADMIN');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $admin = $this->createPlatformAdministrator();
        $admin->assignRole('administrator');
        $admin->healthUnits()->attach($unit);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Doutora Admin']);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient = $this->createPatient($unit, 'PACIENTE VISIVEL PARA ADMIN', $doctor);
        $this->createOrderWithItem($unit, $patient, $doctor, 'medical');

        $response = $this->actingAs($admin)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.results.index'));

        $response->assertOk();
        $response->assertSee('PACIENTE VISIVEL PARA ADMIN');
    }

    public function test_results_are_filtered_by_patient_name(): void
    {
        $unit = $this->createHealthUnit('RESULTS-FILTER');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Doutora Filtro']);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patientMatch = $this->createPatient($unit, 'MARIA DAS DORES SILVA', $doctor);
        $patientOther = $this->createPatient($unit, 'JOAO PEREIRA', $doctor);
        $this->createOrderWithItem($unit, $patientMatch, $doctor, 'medical');
        $this->createOrderWithItem($unit, $patientOther, $doctor, 'medical');

        $response = $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.results.index', ['patient' => 'Maria das Dores']));

        $response->assertOk();
        $response->assertSee('MARIA DAS DORES SILVA');
        $response->assertDontSee('JOAO PEREIRA');
    }

    public function test_print_route_streams_the_pdf_inline_for_the_requesting_doctor_and_audits_access(): void
    {
        Storage::fake('local_private');
        $unit = $this->createHealthUnit('RESULTS-PRINT');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Doutora Impressao']);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient = $this->createPatient($unit, 'PACIENTE COM LAUDO', $doctor);
        [, $item] = $this->createOrderWithItem($unit, $patient, $doctor, 'medical');
        Storage::disk('local_private')->put('laboratory-results/1/result.pdf', '%PDF-1.4 conteúdo fake');
        $result = $item->result()->create([
            'source' => 'synclab',
            'result_text' => 'Resultado',
            'recorded_by' => null,
            'resulted_at' => now(),
            'content_hash' => hash('sha256', 'resultado'),
            'result_pdf_disk' => 'local_private',
            'result_pdf_path' => 'laboratory-results/1/result.pdf',
            'result_pdf_hash' => hash('sha256', 'conteudo'),
            'result_pdf_size' => 20,
            'result_pdf_original_filename' => 'laudo.pdf',
        ]);

        $response = $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.results.print', $result));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'laboratory.result_pdf_viewed']);
    }

    public function test_print_route_denies_a_doctor_who_did_not_request_the_order(): void
    {
        Storage::fake('local_private');
        $unit = $this->createHealthUnit('RESULTS-PRINT-DENY');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $requestingDoctor = $this->createUserWithUnit($unit, ['name' => 'Doutora Solicitante']);
        $requestingDoctor->assignRole('doctor');
        $this->registerDoctor($requestingDoctor, $unit);
        $otherDoctor = $this->createUserWithUnit($unit, ['name' => 'Doutor Estranho']);
        $otherDoctor->assignRole('doctor');
        $this->registerDoctor($otherDoctor, $unit);
        $patient = $this->createPatient($unit, 'PACIENTE PRIVADO', $requestingDoctor);
        [, $item] = $this->createOrderWithItem($unit, $patient, $requestingDoctor, 'medical');
        Storage::disk('local_private')->put('laboratory-results/2/result.pdf', '%PDF-1.4 conteúdo fake');
        $result = $item->result()->create([
            'source' => 'synclab',
            'result_text' => 'Resultado',
            'recorded_by' => null,
            'resulted_at' => now(),
            'content_hash' => hash('sha256', 'resultado-2'),
            'result_pdf_disk' => 'local_private',
            'result_pdf_path' => 'laboratory-results/2/result.pdf',
            'result_pdf_hash' => hash('sha256', 'conteudo-2'),
            'result_pdf_size' => 20,
            'result_pdf_original_filename' => 'laudo.pdf',
        ]);

        $this->actingAs($otherDoctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.results.print', $result))
            ->assertForbidden();
    }

    public function test_print_route_returns_not_found_when_the_result_has_no_pdf_yet(): void
    {
        $unit = $this->createHealthUnit('RESULTS-PRINT-MISSING');
        $this->seed([RolePermissionSeeder::class, OperationalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['name' => 'Doutora Sem Laudo']);
        $doctor->assignRole('doctor');
        $this->registerDoctor($doctor, $unit);
        $patient = $this->createPatient($unit, 'PACIENTE SEM LAUDO', $doctor);
        [, $item] = $this->createOrderWithItem($unit, $patient, $doctor, 'medical');
        $item->result()->create([
            'source' => 'manual',
            'result_text' => 'Resultado sem PDF',
            'recorded_by' => $doctor->getKey(),
            'resulted_at' => now(),
            'content_hash' => hash('sha256', 'sem-pdf'),
        ]);
        $result = $item->result()->sole();

        $this->actingAs($doctor)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('laboratory.results.print', $result))
            ->assertNotFound();
    }

    private function createPatient(HealthUnit $unit, string $normalizedName, User $createdBy): Patient
    {
        return Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P-'.$normalizedName,
            'full_name' => $normalizedName,
            'normalized_name' => $normalizedName,
            'birth_date' => '1985-01-23',
            'sex' => PatientSex::Female,
            'status' => PatientStatus::Active,
            'created_by' => $createdBy->getKey(),
            'updated_by' => $createdBy->getKey(),
        ]);
    }

    /** @return array{ExamOrder, ExamOrderItem} */
    private function createOrderWithItem(HealthUnit $unit, Patient $patient, User $requestedBy, string $origin): array
    {
        $encounter = Encounter::query()->create([
            'encounter_number' => 'ENC-'.$patient->getKey(),
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
            'entry_type_id' => EntryType::query()->where('organization_id', $unit->organization_id)->valueOrFail('id'),
            'arrival_method_id' => ArrivalMethod::query()->where('organization_id', $unit->organization_id)->valueOrFail('id'),
            'current_status' => 'waiting_medical',
            'arrival_at' => now(),
            'registration_at' => now(),
            'created_by' => $requestedBy->getKey(),
        ]);
        $order = ExamOrder::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => null,
            'requested_by' => $requestedBy->getKey(),
            'created_by' => $requestedBy->getKey(),
            'origin' => $origin,
            'status' => 'pending',
            'priority' => 'routine',
            'clinical_indication' => 'Investigação laboratorial.',
            'requested_at' => now(),
        ]);
        $item = $order->items()->create([
            'exam_name' => 'Hemograma completo',
            'group' => 'laboratory',
            'priority' => 'routine',
            'status' => 'resulted',
        ]);

        return [$order, $item];
    }
}
