<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Patients\Application\Actions\SavePatientAction;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\RefreshCoreAndTenantDatabase;
use Tests\TestCase;

final class PatientManagementTest extends TestCase
{
    use RefreshCoreAndTenantDatabase;

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
            ->assertSee('Novo cadastro')
            ->assertSee('name="idempotency_key"', false);

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
            ->getJson(route('patients.search', ['q' => '529.982.247-25']))
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

        $updatePayload = $this->patientPayload();
        unset($updatePayload['idempotency_key']);
        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->put(route('patients.update', $patient), [
                ...$updatePayload,
                'social_name' => 'Maria Vitória',
            ])
            ->assertRedirect(route('patients.show', $patient));

        $this->assertDatabaseHas('patients', [
            'id' => $patient->getKey(),
            'social_name' => 'Maria Vitória',
        ]);
    }

    public function test_patient_creation_reuses_the_same_patient_for_an_idempotent_retry(): void
    {
        $unit = $this->createHealthUnit('PATIENT-IDEMPOTENT');
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $payload = $this->patientPayload();
        $action = app(SavePatientAction::class);

        $first = $action->execute($payload, $user, (int) $unit->getKey());
        $second = $action->execute($payload, $user, (int) $unit->getKey());

        $this->assertSame($first->public_id, $second->public_id);
        $this->assertDatabaseCount('patients', 1);
        $this->assertDatabaseHas('patient_operation_keys', [
            'user_id' => $user->getKey(),
            'route_name' => 'patients.store',
            'idempotency_key' => $payload['idempotency_key'],
            'patient_public_id' => $first->public_id,
            'status' => 'completed',
        ]);
    }

    public function test_patient_creation_rejects_a_reused_key_with_different_payload(): void
    {
        $unit = $this->createHealthUnit('PATIENT-DIVERGENT');
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $payload = $this->patientPayload();
        $action = app(SavePatientAction::class);
        $action->execute($payload, $user, (int) $unit->getKey());

        try {
            $action->execute([
                ...$payload,
                'full_name' => 'Outra Pessoa',
            ], $user, (int) $unit->getKey());
            $this->fail('O replay divergente deveria ter sido rejeitado.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Este formulário já foi usado com dados diferentes. Recarregue a página.'],
                $exception->errors()['idempotency_key'] ?? [],
            );
        }

        $this->assertDatabaseCount('patients', 1);
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

    public function test_patient_numbering_recovers_when_the_sequence_is_behind_existing_records(): void
    {
        $unit = $this->createHealthUnit();
        $this->seed(RolePermissionSeeder::class);
        $user = $this->createUserWithUnit($unit, ['must_change_password' => false]);
        $user->assignRole('receptionist');

        Patient::query()->create([
            'organization_id' => $unit->organization_id,
            'medical_record_number' => 'P00000003',
            'full_name' => 'Paciente importado',
            'normalized_name' => 'PACIENTE IMPORTADO',
            'sex' => 'unknown',
            'reference_health_unit_id' => $unit->getKey(),
            'status' => 'active',
            'created_by' => $user->getKey(),
            'updated_by' => $user->getKey(),
        ]);
        DB::connection('core')->table('core_number_sequences')->updateOrInsert(
            ['scope' => 'patient_mrn', 'date_key' => ''],
            ['current_value' => 2, 'created_at' => now(), 'updated_at' => now()],
        );

        $this->actingAs($user)
            ->withSession(['active_health_unit_id' => $unit->getKey()])
            ->post(route('patients.provisional.store'), [
                'sex' => 'unknown',
                'estimated_age' => 40,
                'estimated_age_range' => 'adult',
                'provisional_description' => 'Paciente sem identificacao no momento do acolhimento.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('patients', [
            'medical_record_number' => 'P00000004',
            'is_provisional' => true,
        ]);
        $this->assertDatabaseHas('core_number_sequences', [
            'scope' => 'patient_mrn',
            'date_key' => '',
            'current_value' => 4,
        ]);
    }

    /** @return array<string, mixed> */
    private function patientPayload(): array
    {
        return [
            'idempotency_key' => (string) Str::ulid(),
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
