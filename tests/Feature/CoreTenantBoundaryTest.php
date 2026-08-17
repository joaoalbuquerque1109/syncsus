<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Jobs\SubmitLaboratoryOrderJob;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientAllergy;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Support\Tenancy\PublicLookupIndex;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantContextNotResolvedException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class CoreTenantBoundaryTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_tenant_models_fail_closed_without_context_but_allow_explicit_resolved_connection(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-CONTEXT');
        $connection = app(TenantConnectionManager::class)->connectionName($unit);
        app(TenantContext::class)->reset();

        try {
            Panel::query()->count();
            $this->fail('TenantModel deveria falhar sem contexto resolvido.');
        } catch (TenantContextNotResolvedException) {
            $this->addToAssertionCount(1);
        }

        // Infrastructure with a previously resolved connection may bypass request context.
        $this->assertSame(0, Panel::on($connection)->count());
    }

    public function test_tenant_writes_store_public_core_reference_and_public_index_idempotently(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-INDEX');
        $panel = Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel principal',
            'public_code' => 'BOUNDARY-PANEL',
        ]);

        $this->assertSame($unit->public_id, $panel->health_unit_public_id);
        $this->assertDatabaseHas('public_lookup_index', [
            'public_id_or_code' => 'BOUNDARY-PANEL',
            'entity_type' => 'panel',
        ], 'core');

        $panel->touch();
        $this->assertSame(1, PublicLookupIndex::query()->where('public_id_or_code', 'BOUNDARY-PANEL')->count());
    }

    public function test_public_panel_never_falls_back_to_a_global_tenant_query(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-PUBLIC');
        Panel::query()->create([
            'health_unit_id' => $unit->getKey(),
            'name' => 'Painel público',
            'public_code' => 'PUBLIC-WITHOUT-INDEX',
        ]);
        PublicLookupIndex::query()->where('public_id_or_code', 'PUBLIC-WITHOUT-INDEX')->delete();
        app(TenantContext::class)->reset();

        $this->get('/panels/PUBLIC-WITHOUT-INDEX')->assertNotFound();
        $this->assertFalse(app(TenantContext::class)->isResolved());
    }

    public function test_laboratory_job_payload_carries_unit_and_connection(): void
    {
        $unit = $this->createHealthUnit('BOUNDARY-JOB');
        $connection = app(TenantConnectionManager::class)->connectionName($unit);
        $job = new SubmitLaboratoryOrderJob(321, (string) $unit->public_id, $connection);

        $this->assertSame(321, $job->transmissionId);
        $this->assertSame($unit->public_id, $job->healthUnitPublicId);
        $this->assertSame($connection, $job->tenantConnection);
        $this->assertSame($connection.':321', $job->uniqueId());
    }

    public function test_patient_legacy_relation_resolves_to_active_tenant_and_stays_isolated_per_unit(): void
    {
        $unitA = $this->createHealthUnit('BOUNDARY-PATIENT-A');
        $user = $this->createUserWithUnit($unitA);

        // Advance the Core patients id sequence so the patient's id diverges from
        // whatever id the tenant-side allergy row would get, proving the legacy
        // patient_id FK is not being matched by coincidence.
        for ($i = 0; $i < 3; $i++) {
            Patient::query()->create([
                'organization_id' => $unitA->organization_id,
                'full_name' => "Filler {$i}",
                'normalized_name' => "filler {$i}",
                'medical_record_number' => "FILLER-{$i}",
                'status' => 'active',
                'sex' => 'unknown',
                'created_by' => $user->getKey(),
            ]);
        }

        $patient = Patient::query()->create([
            'organization_id' => $unitA->organization_id,
            'full_name' => 'Paciente Teste',
            'normalized_name' => 'paciente teste',
            'medical_record_number' => 'MRN-TEST',
            'status' => 'active',
            'sex' => 'unknown',
            'created_by' => $user->getKey(),
        ]);

        $allergy = PatientAllergy::query()->create([
            'patient_id' => $patient->getKey(),
            'substance' => 'Penicilina',
            'severity' => 'moderate',
            'recorded_by' => $user->getKey(),
            'recorded_at' => now(),
        ]);

        $this->assertNotSame($patient->getKey(), $allergy->getKey());
        $this->assertNotNull($allergy->unit_patient_id);
        $this->assertSame($unitA->public_id, $allergy->health_unit_public_id);
        $this->assertSame(1, $patient->allergies()->count());
        $this->assertTrue($patient->allergies()->first()?->is($allergy));

        $unitB = HealthUnit::query()->create([
            'organization_id' => $unitA->organization_id,
            'code' => 'BOUNDARY-PATIENT-B',
            'cnes_code' => '1234567',
            'name' => 'Unidade B',
            'is_active' => true,
        ]);
        $this->activateTenant($unitB);
        $this->assertSame(0, $patient->allergies()->count());

        $this->activateTenant($unitA);
        $this->assertSame(1, $patient->allergies()->count());
    }
}
