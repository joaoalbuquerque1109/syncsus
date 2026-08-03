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
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Support\Collection;
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

        $this->assertSame('12345678901', data_get($payload, 'pedido_lab.paciente.cpf'));
        $this->assertSame('127', data_get($payload, 'pedido_lab.exames.0.codigo'));
        $this->assertArrayNotHasKey('amostras', $payload['pedido_lab']['exames'][0]);
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
            'medical_record_number' => 'P00000001',
            'full_name' => 'Paciente Teste',
            'birth_date' => '1985-01-23',
            'sex' => PatientSex::Female,
        ]);
        $patient->setRelation('identifiers', new Collection($identifiers));
        $unit = new HealthUnit(['code' => 'CENTRAL', 'cnes_code' => '1234567']);
        $encounter = new Encounter;
        $encounter->setRelation('patient', $patient);
        $encounter->setRelation('healthUnit', $unit);
        $item = new ExamOrderItem([
            'laboratory_integration_id' => 10,
            'external_exam_code' => '127',
            'exam_name' => 'Hemograma completo',
        ]);
        $item->setRelation('laboratoryExam', null);
        $order = new ExamOrder(['requested_by' => 15]);
        $order->setRelation('requestedBy', new User(['id' => 15]));
        $order->setRelation('encounter', $encounter);
        $order->setRelation('items', new Collection([$item]));

        return [$order, $integration];
    }
}
