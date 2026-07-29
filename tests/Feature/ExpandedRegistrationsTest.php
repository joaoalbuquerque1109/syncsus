<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Administration\Infrastructure\Eloquent\Specialty;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExpandedRegistrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_register_a_professional_with_council_specialty_and_unit(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $professionalUser = $this->createUserWithUnit($unit, ['email' => 'doctor@example.test']);
        $professionalUser->assignRole('doctor');
        $specialty = Specialty::query()->create([
            'organization_id' => $unit->organization_id,
            'code' => 'CARDIO',
            'name' => 'Cardiologia',
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('administration.professionals.store'), [
                'user_id' => $professionalUser->getKey(),
                'institutional_code' => 'MED-001',
                'profession_type' => 'doctor',
                'treatment_name' => 'Dra.',
                'full_name' => 'Carolina Médica',
                'cpf' => '52998224725',
                'is_active' => '1',
                'health_unit_ids' => [$unit->getKey()],
                'specialty_ids' => [$specialty->getKey()],
                'primary_specialty_id' => $specialty->getKey(),
                'specialty_rqe' => [$specialty->getKey() => 'RQE-123'],
                'registrations' => [[
                    'council_type' => 'CRM',
                    'registration_number' => '998877',
                    'state' => 'CE',
                    'is_primary' => '1',
                ]],
            ])
            ->assertRedirect(route('administration.professionals.index'));

        $this->assertDatabaseHas('health_professionals', [
            'user_id' => $professionalUser->getKey(),
            'institutional_code' => 'MED-001',
            'cpf' => '52998224725',
        ]);
        $this->assertDatabaseHas('professional_registrations', [
            'council_type' => 'CRM',
            'registration_number' => '998877',
            'state' => 'CE',
        ]);
        $this->assertDatabaseHas('health_professional_specialty', [
            'specialty_id' => $specialty->getKey(),
            'rqe_number' => 'RQE-123',
            'is_primary' => true,
        ]);
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('administration.professionals.index'))
            ->assertOk()
            ->assertSee('Carolina Médica');
        $professional = $professionalUser->professionalProfile()->firstOrFail();
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('administration.professionals.create'))
            ->assertOk();
        $this->actingAs($administrator)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('administration.professionals.edit', $professional))
            ->assertOk()
            ->assertSee('RQE-123');
    }

    public function test_expanded_patient_registration_and_clinical_history_are_persisted(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $receptionist = $this->createUserWithUnit($unit);
        $receptionist->assignRole('receptionist');
        $doctor = $this->createUserWithUnit($unit);
        $doctor->assignRole('doctor');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($receptionist)->withSession($session)->post(route('patients.store'), [
            'full_name' => 'Maria da Silva',
            'birth_date' => '1987-06-14',
            'sex' => 'female',
            'cpf' => '52998224725',
            'cns' => '123456789012345',
            'rg' => '2000.123-4',
            'rg_issuer' => 'SSP',
            'rg_issuer_state' => 'CE',
            'rg_issued_at' => '2005-01-10',
            'passport' => 'BR123456',
            'passport_issuer' => 'PF',
            'mother_name' => 'Ana da Silva',
            'ethnicity' => 'Não informada',
            'birth_city' => 'Fortaleza',
            'birth_city_ibge_code' => '2304400',
            'number_of_children' => 2,
            'mobile' => '(85) 99999-1000',
            'phone2' => '(85) 3333-2000',
            'state' => 'CE',
            'city' => 'Fortaleza',
            'city_ibge_code' => '2304400',
            'area_type' => 'urban',
            'guardian_name' => 'José da Silva',
            'guardian_relationship' => 'Pai',
            'guardian_reason' => 'Acompanhamento',
            'financial_guardian_name' => 'Ana da Silva',
            'financial_guardian_cpf' => '11144477735',
            'financial_guardian_relationship' => 'Mãe',
            'administrative_notes' => 'Cadastro conferido na recepção.',
        ])->assertRedirect();

        $patient = Patient::query()->sole();
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $patient->getKey(), 'type' => 'rg', 'normalized_value' => '20001234']);
        $this->assertDatabaseHas('patient_identifiers', ['patient_id' => $patient->getKey(), 'type' => 'passport', 'normalized_value' => 'BR123456']);
        $this->assertDatabaseHas('patient_contacts', ['patient_id' => $patient->getKey(), 'type' => 'phone2']);
        $this->assertDatabaseHas('patient_guardians', ['patient_id' => $patient->getKey(), 'guardian_type' => 'financial']);
        $this->assertSame('2304400', $patient->birth_city_ibge_code);

        $this->actingAs($doctor)->withSession($session)->post(
            route('patients.clinical-history.allergies.store', $patient),
            ['substance' => 'Dipirona', 'reaction' => 'Urticária', 'severity' => 'moderate', 'status' => 'active'],
        )->assertRedirect();
        $this->actingAs($doctor)->withSession($session)->post(
            route('patients.clinical-history.conditions.store', $patient),
            ['description' => 'Hipertensão arterial', 'code' => 'I10', 'status' => 'active'],
        )->assertRedirect();
        $this->actingAs($doctor)->withSession($session)->post(
            route('patients.clinical-history.medications.store', $patient),
            ['medication_name' => 'Losartana', 'dosage' => '50 mg', 'frequency' => '12/12h', 'status' => 'active'],
        )->assertRedirect();
        $this->actingAs($doctor)->withSession($session)->put(
            route('patients.clinical-history.social.update', $patient),
            ['smoking_status' => 'never', 'alcohol_use' => 'occasional'],
        )->assertRedirect();

        $this->assertDatabaseHas('patient_allergies', ['patient_id' => $patient->getKey(), 'substance' => 'Dipirona']);
        $this->assertDatabaseHas('patient_conditions', ['patient_id' => $patient->getKey(), 'code' => 'I10']);
        $this->assertDatabaseHas('patient_medications', ['patient_id' => $patient->getKey(), 'medication_name' => 'Losartana']);
        $this->assertDatabaseHas('patient_social_histories', ['patient_id' => $patient->getKey(), 'smoking_status' => 'never']);
        $this->assertDatabaseHas('audit_logs', ['patient_id' => $patient->getKey(), 'action' => 'patient.medication_recorded']);
        $this->actingAs($doctor)->withSession($session)
            ->get(route('patients.show', $patient))
            ->assertOk()
            ->assertSee('Resumo clínico longitudinal')
            ->assertSee('Losartana');
    }

    public function test_administrator_can_manage_users_and_catalogs(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $administrator = $this->createPlatformAdministrator();
        $administrator->assignRole('administrator');
        $manager = $this->createUserWithUnit($unit, ['email' => 'manager@example.test']);
        $manager->assignRole('manager');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($administrator)->withSession($session)->post(route('administration.users.store'), [
            'name' => 'Novo Usuário',
            'email' => 'novo@example.test',
            'password' => 'Senha#Temporaria2026',
            'roles' => ['doctor'],
            'health_unit_ids' => [$unit->getKey()],
            'default_health_unit_id' => $unit->getKey(),
            'is_active' => '1',
        ])->assertRedirect();

        $created = User::query()->where('email', 'novo@example.test')->firstOrFail();
        $this->assertTrue($created->hasRole('doctor'));
        $this->assertTrue($created->healthUnits()->whereKey($unit->getKey())->exists());

        $this->actingAs($administrator)->withSession($session)->post(
            route('administration.catalogs.store', 'specialties'),
            ['code' => 'NEURO', 'name' => 'Neurologia', 'display_order' => 10, 'is_active' => '1'],
        )->assertRedirect();

        $this->assertDatabaseHas('specialties', ['code' => 'NEURO', 'name' => 'Neurologia', 'is_active' => true]);
        $this->actingAs($administrator)->withSession($session)
            ->get(route('administration.users.index'))->assertOk()->assertSee('Novo Usuário');
        $this->actingAs($administrator)->withSession($session)
            ->get(route('administration.catalogs.index'))->assertOk()->assertSee('Neurologia');
    }
}
