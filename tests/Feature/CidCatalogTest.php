<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use Database\Seeders\MedicalCatalogSeeder;
use Database\Seeders\RolePermissionSeeder;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class CidCatalogTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

    public function test_catalog_imports_every_category_from_the_provided_spreadsheet(): void
    {
        $this->seed(MedicalCatalogSeeder::class);

        $this->assertSame(1835, DiagnosisCode::query()->whereRaw('LENGTH(code) = 3')->count());
        $this->assertDatabaseHas('diagnosis_codes', ['code' => 'A00', 'description' => 'Cólera', 'is_active' => true]);
        $this->assertDatabaseHas('diagnosis_codes', [
            'code' => 'Z99',
            'description' => 'Dependência de máquinas e dispositivos capacitantes, não classificados em outra parte',
            'is_active' => true,
        ]);
    }

    public function test_doctor_can_search_active_cid_codes_without_loading_the_whole_catalog(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed([RolePermissionSeeder::class, MedicalCatalogSeeder::class]);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($doctor)->withSession($session)
            ->getJson(route('medical.cid-codes.search', ['q' => 'A00']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'A00')
            ->assertJsonPath('data.0.description', 'Cólera');

        DiagnosisCode::query()->where('code', 'A00')->update(['is_active' => false]);

        $this->actingAs($doctor)->withSession($session)
            ->getJson(route('medical.cid-codes.search', ['q' => 'A00']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($doctor)->withSession($session)
            ->getJson(route('medical.cid-codes.search', ['q' => 'A']))
            ->assertUnprocessable();
    }
}
