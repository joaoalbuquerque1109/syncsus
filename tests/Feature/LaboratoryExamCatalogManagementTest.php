<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LaboratoryExamCatalogManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_manages_only_the_active_units_laboratory_catalog(): void
    {
        $unit = $this->createHealthUnit('CENTRAL');
        $otherUnit = $this->createHealthUnit('NORTH');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $integration = $this->integration($unit->organization_id, $unit->getKey());
        $otherIntegration = $this->integration($otherUnit->organization_id, $otherUnit->getKey());
        $integration->exams()->create([
            'external_code' => '127',
            'acronym' => 'HEM',
            'name' => 'Hemograma completo',
            'source_version' => 'catalog-v1',
            'is_active' => true,
        ]);
        $foreignExam = $otherIntegration->exams()->create([
            'external_code' => '999',
            'name' => 'Exame de outra unidade',
            'is_active' => true,
        ]);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->get(route('administration.catalogs.index', ['tab' => 'exams']))
            ->assertOk()
            ->assertSee('Exames laboratoriais')
            ->assertSee('Hemograma completo')
            ->assertDontSee('Exame de outra unidade');

        $this->actingAs($manager)->withSession($session)
            ->post(route('administration.catalogs.store', 'laboratory-exams'), [
                'external_code' => ' 501 ',
                'name' => 'Proteína C reativa ultrassensível',
                'short_name' => 'PCR ultrassensível',
                'acronym' => ' pcr-us ',
                'integration_acronym' => ' pcrus ',
                'sus_procedure_code' => '0202030202',
                'group_name' => 'Bioquímica',
                'turnaround_minutes' => 120,
                'collection_instructions' => 'Coletar em tubo apropriado.',
                'synonyms_text' => "PCR ultrassensível\nPCR-US; pcr-us",
                'is_active' => '1',
            ])
            ->assertRedirect(route('administration.catalogs.index', ['tab' => 'exams']));

        $created = LaboratoryExam::query()->where('external_code', '501')->sole();
        $this->assertSame($integration->getKey(), $created->laboratory_integration_id);
        $this->assertSame('PCR-US', $created->acronym);
        $this->assertSame('PCRUS', $created->integration_acronym);
        $this->assertSame(['PCR ultrassensível', 'PCR-US'], $created->synonyms);
        $this->assertNull($created->source_version);
        $this->assertTrue($created->is_active);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $manager->getKey(),
            'health_unit_id' => $unit->getKey(),
            'action' => 'administration.catalog_saved',
        ]);

        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.catalogs.update', [
                'catalog' => 'laboratory-exams',
                'record' => $created->getKey(),
            ]), [
                'external_code' => '501',
                'name' => 'Proteína C reativa de alta sensibilidade',
                'sus_procedure_code' => '0202030202',
                'synonyms_text' => 'PCR de alta sensibilidade',
            ])
            ->assertRedirect(route('administration.catalogs.index', ['tab' => 'exams']));

        $created->refresh();
        $this->assertSame('Proteína C reativa de alta sensibilidade', $created->name);
        $this->assertFalse($created->is_active);

        $this->actingAs($manager)->withSession($session)
            ->put(route('administration.catalogs.update', [
                'catalog' => 'laboratory-exams',
                'record' => $foreignExam->getKey(),
            ]), [
                'external_code' => '999',
                'name' => 'Alteração indevida',
                'is_active' => '1',
            ])
            ->assertNotFound();
    }

    public function test_exam_catalog_rejects_duplicate_external_code_and_invalid_sus_code(): void
    {
        $unit = $this->createHealthUnit('CENTRAL');
        $this->seed(RolePermissionSeeder::class);
        $manager = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $manager->assignRole('manager');
        $integration = $this->integration($unit->organization_id, $unit->getKey());
        $integration->exams()->create([
            'external_code' => '127',
            'name' => 'Hemograma completo',
            'is_active' => true,
        ]);
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($manager)->withSession($session)
            ->from(route('administration.catalogs.index', ['tab' => 'exams']))
            ->post(route('administration.catalogs.store', 'laboratory-exams'), [
                'external_code' => '127',
                'name' => 'Exame duplicado',
                'sus_procedure_code' => '123',
                'is_active' => '1',
            ])
            ->assertRedirect(route('administration.catalogs.index', ['tab' => 'exams']))
            ->assertSessionHasErrors(['external_code', 'sus_procedure_code']);

        $this->assertDatabaseCount('laboratory_exams', 1);
    }

    private function integration(int $organizationId, int $healthUnitId): LaboratoryIntegration
    {
        return LaboratoryIntegration::query()->create([
            'organization_id' => $organizationId,
            'health_unit_id' => $healthUnitId,
            'provider' => 'synclab',
            'is_active' => true,
        ]);
    }
}
