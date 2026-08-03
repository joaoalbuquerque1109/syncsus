<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

use App\Modules\Laboratory\Application\Data\LaboratoryOrderPayload;
use App\Modules\Laboratory\Application\Exceptions\InvalidLaboratoryOrder;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Patients\Domain\Enums\PatientIdentifierType;
use App\Modules\Patients\Domain\Enums\PatientSex;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;

final class SynclabOrderPayloadBuilder
{
    public function build(
        ExamOrder $order,
        LaboratoryIntegration $integration,
        string $externalOrderNumber,
    ): LaboratoryOrderPayload {
        $order->loadMissing([
            'requestedBy',
            'encounter.healthUnit',
            'encounter.patient.identifiers',
            'items.laboratoryExam',
        ]);

        $patient = $order->encounter->patient;
        $identifiers = $patient->identifiers->keyBy(
            static fn ($identifier): string => $identifier->typeValue(),
        );
        $cpf = $identifiers->get(PatientIdentifierType::Cpf->value)?->normalized_value;
        $cns = $identifiers->get(PatientIdentifierType::Cns->value)?->normalized_value;

        if (trim((string) $patient->full_name) === '') {
            throw new InvalidLaboratoryOrder('O nome do paciente e obrigatorio para o Synclab.');
        }
        if ($cpf === null && $cns === null) {
            throw new InvalidLaboratoryOrder('O paciente precisa possuir CPF ou Cartao Nacional de Saude.');
        }

        $items = $order->items
            ->where('laboratory_integration_id', $integration->getKey())
            ->values();
        if ($items->isEmpty()) {
            throw new InvalidLaboratoryOrder('A ordem nao possui exames destinados a esta integracao.');
        }

        $exams = $items->map(function ($item): array {
            $code = trim((string) ($item->external_exam_code ?: $item->laboratoryExam?->external_code));
            if ($code === '') {
                throw new InvalidLaboratoryOrder('Todo exame laboratorial precisa de um codigo externo.');
            }

            // Confirmado com o Synclab: a requisicao nasce sem amostra. A amostra sera
            // identificada posteriormente no fluxo laboratorial, portanto nao enviamos cbarra.
            return [
                'codigo' => $code,
                'descricao' => (string) $item->exam_name,
            ];
        })->all();

        $unit = $order->encounter->healthUnit;
        $patientData = array_filter([
            'codigo' => (string) $patient->medical_record_number,
            'nome' => (string) $patient->full_name,
            'sexo' => $this->synclabSex($patient),
            'dt_nascimento' => $patient->birth_date?->format('Y-m-d'),
            'cpf' => $cpf,
            'cns' => $cns,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return new LaboratoryOrderPayload([
            'ordem_servico' => $externalOrderNumber,
            'codigo_pedido' => $externalOrderNumber,
            'identificador' => 'SYNC-SUS',
            'usuario_web_id' => (string) $order->requested_by,
            'pedido' => array_filter([
                'codigo' => $externalOrderNumber,
                'ordem_servico' => $externalOrderNumber,
                'posto' => (string) $unit->code,
                'cnesUnidadeExecutante' => $unit->cnes_code,
                'convenio' => (string) data_get($integration->settings, 'agreement', 'SUS'),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'paciente' => $patientData,
            'exames' => $exams,
        ]);
    }

    private function synclabSex(Patient $patient): string
    {
        return match ($patient->sexEnum()) {
            PatientSex::Female => 'F',
            PatientSex::Male => 'M',
            PatientSex::Intersex => 'I',
            PatientSex::Unknown => '',
        };
    }
}
