<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class LaboratoryIntegrationFoundationTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_integration_is_scoped_to_one_health_unit_and_disabled_by_default(): void
    {
        $unit = $this->createHealthUnit();

        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
        ]);

        $this->assertFalse($integration->is_active);
        $this->assertFalse($integration->transmission_enabled);
        $this->assertFalse($integration->result_sync_enabled);
        $this->assertSame($unit->getKey(), $integration->resolveHealthUnit()?->getKey());
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $unit = $this->createHealthUnit();
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
            'username' => 'integration-user',
            'password' => 'integration-secret',
        ]);

        $this->assertSame('integration-user', $integration->username);
        $this->assertSame('integration-secret', $integration->password);
        $this->assertNotSame('integration-user', $integration->getRawOriginal('username'));
        $this->assertNotSame('integration-secret', $integration->getRawOriginal('password'));
    }

    public function test_reduced_catalog_preserves_external_codes_and_components(): void
    {
        $unit = $this->createHealthUnit();
        $integration = LaboratoryIntegration::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'provider' => 'synclab',
        ]);
        $material = $integration->materials()->create([
            'external_code' => 'SAN_EDTA',
            'name' => 'Sangue total EDTA',
            'container' => 'Tubo EDTA',
            'cap_color' => 'Roxa',
        ]);
        $exam = $integration->exams()->create([
            'laboratory_material_id' => $material->getKey(),
            'external_code' => '127',
            'acronym' => 'HEM',
            'name' => 'Hemograma completo',
            'synonyms' => ['hemograma', 'hemograma completo'],
        ]);
        $exam->components()->create([
            'external_code' => 'HEM',
            'interface_acronym' => 'hemacias',
            'name' => 'Hemacias',
            'data_type' => 'decimal',
            'unit' => 'milhoes/mm3',
        ]);

        $loaded = LaboratoryExam::query()->with(['material', 'components'])->sole();

        $this->assertSame('127', $loaded->external_code);
        $this->assertSame('Sangue total EDTA', $loaded->material?->name);
        $this->assertSame('hemacias', $loaded->components->sole()->interface_acronym);
    }
}
