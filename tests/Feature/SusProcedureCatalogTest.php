<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Medical\Infrastructure\Eloquent\SusProcedure;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SusProcedureCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SusProcedureCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_imports_the_versioned_snapshot_idempotently_without_exact_count_assertion(): void
    {
        $this->seed(SusProcedureCatalogSeeder::class);
        $count = SusProcedure::query()->count();

        $this->assertGreaterThanOrEqual(4000, $count);
        $this->assertDatabaseHas('sus_procedures', [
            'code' => '0101010010',
            'complexity' => 'N',
            'sex_restriction' => 'N',
            'minimum_age_months' => null,
            'maximum_age_months' => null,
            'is_active' => true,
        ]);

        SusProcedure::query()->where('code', '0101010010')->update([
            'description' => 'Descrição desatualizada',
            'is_active' => false,
        ]);
        $this->seed(SusProcedureCatalogSeeder::class);

        $this->assertSame($count, SusProcedure::query()->count());
        $this->assertDatabaseMissing('sus_procedures', [
            'code' => '0101010010',
            'description' => 'Descrição desatualizada',
        ]);
        $this->assertTrue(SusProcedure::query()->where('code', '0101010010')->sole()->is_active);
    }

    public function test_doctor_search_prioritizes_exact_code_and_excludes_inactive_procedures(): void
    {
        $unit = $this->createHealthUnit('SUS-CATALOG');
        $this->seed(RolePermissionSeeder::class);
        $doctor = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $doctor->assignRole('doctor');
        $session = ['active_health_unit_id' => $unit->getKey()];
        SusProcedure::query()->create([
            'code' => '0202010120',
            'description' => 'DOSAGEM DE ÁCIDO ÚRICO',
            'complexity' => 'M',
            'is_active' => true,
        ]);
        SusProcedure::query()->create([
            'code' => '9999999991',
            'description' => 'REFERÊNCIA AO PROCEDIMENTO 0202010120',
            'is_active' => true,
        ]);
        SusProcedure::query()->create([
            'code' => '0202010121',
            'description' => 'PROCEDIMENTO INATIVO',
            'is_active' => false,
        ]);

        $this->actingAs($doctor)->withSession($session)
            ->getJson(route('medical.sus-procedures.search', ['q' => '0202010120']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.code', '0202010120')
            ->assertJsonPath('data.0.description', 'DOSAGEM DE ÁCIDO ÚRICO')
            ->assertJsonPath('data.0.complexity', 'M');

        $this->actingAs($doctor)->withSession($session)
            ->getJson(route('medical.sus-procedures.search', ['q' => '0202010121']))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($doctor)->withSession($session)
            ->getJson(route('medical.sus-procedures.search', ['q' => 'ÁCIDO ÚRICO']))
            ->assertOk()
            ->assertJsonPath('data.0.code', '0202010120');
    }
}
