<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PatientManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_receptionist_can_create_search_update_and_access_patient_safely(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $user->assignRole('receptionist');

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('patients.create'))
            ->assertOk()
            ->assertSee('Novo cadastro');

        $response = $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('patients.store'), $this->patientPayload());

        $patient = Patient::query()->with('identifiers')->sole();
        $response->assertRedirect(route('patients.show', $patient));
        $this->assertSame('MARIA DA SILVA', $patient->normalized_name);
        $this->assertSame('P00000001', $patient->medical_record_number);
        $this->assertDatabaseHas('patient_identifiers', [
            'patient_id' => $patient->getKey(),
            'type' => 'cpf',
            'normalized_value' => '52998224725',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'patient.created',
            'patient_id' => $patient->getKey(),
            'health_unit_id' => $unit->getKey(),
        ]);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->getJson(route('patients.search', ['q' => '529.982']))
            ->assertOk()
            ->assertJsonPath('data.0.public_id', $patient->public_id)
            ->assertJsonPath('data.0.identifiers.0.value', '*******4725')
            ->assertJsonMissing(['normalized_value' => '52998224725']);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->get(route('patients.show', $patient))
            ->assertOk()
            ->assertSee('Maria da Silva');
        $this->assertDatabaseHas('patient_access_logs', [
            'patient_id' => $patient->getKey(),
            'user_id' => $user->getKey(),
            'health_unit_id' => $unit->getKey(),
            'access_type' => 'record_view',
        ]);

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->put(route('patients.update', $patient), [
                ...$this->patientPayload(),
                'social_name' => 'Maria Vitória',
            ])
            ->assertRedirect(route('patients.show', $patient));

        $this->assertDatabaseHas('patients', [
            'id' => $patient->getKey(),
            'social_name' => 'Maria Vitória',
        ]);
    }

    public function test_duplicate_cpf_is_rejected_and_provisional_patient_can_be_created(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $user->assignRole('receptionist');
        $session = ['active_health_unit_id' => $unit->getKey()];

        $this->actingAs($user)->withSession($session)->post(route('patients.store'), $this->patientPayload())->assertRedirect();
        $this->actingAs($user)->withSession($session)->post(route('patients.store'), [
            ...$this->patientPayload(),
            'full_name' => 'Outra Pessoa',
        ])->assertSessionHasErrors('cpf');
        $this->assertDatabaseCount('patients', 1);

        $this->actingAs($user)->withSession($session)->post(route('patients.provisional.store'), [
            'sex' => 'unknown',
            'estimated_age' => 50,
            'estimated_age_range' => 'adult',
            'provisional_description' => 'Pessoa encontrada sem documentos e sem acompanhante.',
        ])->assertRedirect();

        $this->assertDatabaseHas('patients', [
            'is_provisional' => true,
            'full_name' => 'Paciente não identificado',
            'medical_record_number' => 'P00000002',
        ]);
    }

    /** @return array<string, mixed> */
    private function patientPayload(): array
    {
        return [
            'full_name' => 'Maria da Silva',
            'birth_date' => '1987-06-14',
            'sex' => 'female',
            'cpf' => '529.982.247-25',
            'cns' => '123456789012345',
            'mother_name' => 'Ana da Silva',
            'mobile' => '(85) 99999-1111',
            'postal_code' => '60000-000',
            'state' => 'CE',
            'city' => 'Fortaleza',
            'district' => 'Centro',
            'street' => 'Rua Demonstrativa',
            'number' => '100',
        ];
    }
}
