<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Exceptions\InvalidLaboratoryOrder;
use App\Modules\Laboratory\Application\Services\SynclabOrderPayloadBuilder;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use App\Modules\Patients\Domain\Enums\PatientIdentifierType;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientIdentifier;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Professionals\Infrastructure\Eloquent\ProfessionalRegistration;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SynclabOrderPayloadBuilderTest extends TestCase
{
    public function test_request_contains_patient_identifier_and_no_sample(): void
    {
        [$order, $integration] = $this->orderWithIdentifiers([
            new PatientIdentifier([
                'type' => PatientIdentifierType::Cpf,
                'normalized_value' => '12345678901',
            ]),
        ]);

        $payload = app(SynclabOrderPayloadBuilder::class)
            ->build($order, $integration, '0000000000123')
            ->toArray();

        $this->assertSame('SYNC HOSP', data_get($payload, 'pedido_lab.identificador'));
        $this->assertSame('27', data_get($payload, 'pedido_lab.usuario_web_id'));
        $this->assertSame('Dra. Teste', data_get($payload, 'pedido_lab.pedido.profissional'));
        $this->assertSame('PE', data_get($payload, 'pedido_lab.pedido.ufconselho'));
        $this->assertSame('CRM', data_get($payload, 'pedido_lab.pedido.orgaoemissor'));
        $this->assertSame('12345', data_get($payload, 'pedido_lab.pedido.numconselho'));
        $this->assertSame('987654', data_get($payload, 'pedido_lab.paciente.codigo'));
        $this->assertSame('12345678901', data_get($payload, 'pedido_lab.paciente.cpf'));
        $this->assertSame(127, data_get($payload, 'pedido_lab.exames.0.codigo'));
        $this->assertSame(1234567, data_get($payload, 'pedido_lab.pedido.cnesUnidadeExecutante'));
        $this->assertSame([], $payload['pedido_lab']['exames'][0]['amostras']);
        $this->assertArrayNotHasKey('cbarra', $payload['pedido_lab']['exames'][0]);
        $this->assertSame([], $payload['pedido_lab']['exames'][0]['itens']);
        $this->assertArrayNotHasKey('sigla', $payload['pedido_lab']['exames'][0]);
        $this->assertArrayNotHasKey('identificador_externo', $payload['pedido_lab']['pedido']);
        $this->assertArrayNotHasKey('identificador_externo', $payload['pedido_lab']['paciente']);
    }

    public function test_transition_contract_adds_public_identifiers_without_replacing_numeric_fields(): void
    {
        config()->set('sync_sus.synclab.public_identifiers_enabled', true);
        [$order, $integration] = $this->orderWithIdentifiers([
            new PatientIdentifier([
                'type' => PatientIdentifierType::Cpf,
                'normalized_value' => '12345678901',
            ]),
        ]);

        $payload = app(SynclabOrderPayloadBuilder::class)
            ->build($order, $integration, '0000000000123')
            ->toArray();

        $this->assertSame('0000000000123', data_get($payload, 'pedido_lab.ordem_servico'));
        $this->assertSame('0000000000123', data_get($payload, 'pedido_lab.codigo_pedido'));
        $this->assertSame('0000000000123', data_get($payload, 'pedido_lab.pedido.codigo'));
        $this->assertSame('987654', data_get($payload, 'pedido_lab.paciente.codigo'));
        $this->assertSame($order->public_id, data_get($payload, 'pedido_lab.pedido.identificador_externo'));
        $this->assertSame(
            $order->encounter->patient->public_id,
            data_get($payload, 'pedido_lab.paciente.identificador_externo'),
        );
    }

    public function test_transition_contract_rejects_missing_public_identifiers(): void
    {
        config()->set('sync_sus.synclab.public_identifiers_enabled', true);
        [$order, $integration] = $this->orderWithIdentifiers([
            new PatientIdentifier([
                'type' => PatientIdentifierType::Cpf,
                'normalized_value' => '12345678901',
            ]),
        ]);
        $order->encounter->patient->public_id = null;

        $this->expectException(InvalidLaboratoryOrder::class);
        $this->expectExceptionMessage('ULIDs validos');

        app(SynclabOrderPayloadBuilder::class)->build($order, $integration, '123');
    }

    public function test_request_requires_cpf_or_cns(): void
    {
        [$order, $integration] = $this->orderWithIdentifiers([]);

        $this->expectException(InvalidLaboratoryOrder::class);
        $this->expectExceptionMessage('CPF ou Cartao Nacional de Saude');

        app(SynclabOrderPayloadBuilder::class)->build($order, $integration, '123');
    }

    /**
     * @param  list<PatientIdentifier>  $identifiers
     * @return array{ExamOrder, LaboratoryIntegration}
     */
    private function orderWithIdentifiers(array $identifiers): array
    {
        $integration = new LaboratoryIntegration(['id' => 10, 'settings' => ['agreement' => 'SUS']]);
        $patient = new Patient([
            'id' => 987654,
            'public_id' => (string) Str::ulid(),
            'medical_record_number' => 'P00000001',
            'full_name' => 'Paciente Teste',
            'birth_date' => '1985-01-23',
            'sex' => PatientSex::Female,
        ]);
        $patient->setRelation('identifiers', new Collection($identifiers));
        $unit = new HealthUnit(['code' => 'CENTRAL', 'name' => 'Unidade Central', 'cnes_code' => '1234567']);
        $encounter = new Encounter;
        $encounter->setRelation('patient', $patient);
        $encounter->setRelation('healthUnit', $unit);
        $item = new ExamOrderItem([
            'laboratory_integration_id' => 10,
            'external_exam_code' => '127',
            'exam_name' => 'Hemograma completo',
        ]);
        $item->setRelation('laboratoryExam', null);
        $order = new ExamOrder([
            'public_id' => (string) Str::ulid(),
            'requested_by' => 15,
            'created_by' => 27,
            'origin' => 'reception',
            'priority' => 'routine',
            'requested_at' => now(),
        ]);
        $user = new User(['id' => 15, 'name' => 'Dra. Teste']);
        $professional = new HealthProfessional([
            'institutional_code' => 'MED-15',
            'treatment_name' => 'Dra.',
            'full_name' => 'Teste',
        ]);
        $professional->setRelation('registrations', new Collection([
            new ProfessionalRegistration([
                'council_type' => 'CRM',
                'registration_number' => '12345',
                'state' => 'PE',
                'is_primary' => true,
            ]),
        ]));
        $user->setRelation('professionalProfile', $professional);
        $order->setRelation('requestedBy', $user);
        $order->setRelation('encounter', $encounter);
        $order->setRelation('items', new Collection([$item]));

        return [$order, $integration];
    }
}
