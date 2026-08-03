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
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use DateTimeInterface;

final class SynclabOrderPayloadBuilder
{
    public function build(
        ExamOrder $order,
        LaboratoryIntegration $integration,
        string $externalOrderNumber,
    ): LaboratoryOrderPayload {
        $order->loadMissing([
            'requestedBy.professionalProfile.registrations',
            'encounter.healthUnit',
            'encounter.currentDepartment',
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
        $patientId = (string) $patient->getKey();
        if ($patientId === '' || ! ctype_digit($patientId)) {
            throw new InvalidLaboratoryOrder('O paciente precisa possuir um ID numerico para o Synclab.');
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

            // Synclab requires both collections in each exam even when sample
            // identification and components are deferred. They intentionally
            // remain empty in the current outbound-only integration scope.
            return [
                'codigo' => ctype_digit($code) ? (int) $code : $code,
                'amostras' => [],
                'descricao' => (string) $item->exam_name,
                'itens' => [],
            ];
        })->all();

        $unit = $order->encounter->healthUnit;
        $cnes = preg_replace('/\D/', '', (string) $unit->cnes_code);
        if (strlen($cnes) !== 7) {
            throw new InvalidLaboratoryOrder('A unidade precisa possuir um codigo CNES valido com 7 digitos.');
        }
        $requestedBy = $order->requestedBy;
        $profileValue = $requestedBy->getRelationValue('professionalProfile');
        $institutionalCode = (string) $order->requested_by;
        $professionalName = (string) $requestedBy->name;
        $registration = null;
        if ($profileValue instanceof HealthProfessional) {
            $institutionalCode = (string) $profileValue->institutional_code;
            $professionalName = $profileValue->displayName();
            $registration = $profileValue->registrations->firstWhere('is_primary', true)
                ?? $profileValue->registrations->first();
        }
        $birthDate = $patient->getAttribute('birth_date');
        $requestedAt = $order->getAttribute('requested_at');
        $patientData = array_filter([
            'codigo' => $patientId,
            'nome' => (string) $patient->full_name,
            'sexo' => $this->synclabSex($patient),
            'dt_nascimento' => $birthDate instanceof DateTimeInterface ? $birthDate->format('Y-m-d') : null,
            'cpf' => $cpf,
            'cns' => $cns,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return new LaboratoryOrderPayload([
            'ordem_servico' => $externalOrderNumber,
            'codigo_pedido' => $externalOrderNumber,
            'identificador' => 'SYNC SUS',
            'usuario_web_id' => $institutionalCode,
            'pedido' => array_filter([
                'codigo' => $externalOrderNumber,
                'ordem_servico' => $externalOrderNumber,
                'origem' => (string) $unit->name,
                'profissional' => $professionalName,
                'ufconselho' => $registration?->state,
                'orgaoemissor' => $registration?->council_type,
                'numconselho' => $registration?->registration_number,
                'posto' => (string) ($order->encounter->currentDepartment->name ?? $unit->name),
                'urgente' => $order->priority === 'routine' ? 'N' : 'S',
                'cnesUnidadeExecutante' => (int) $cnes,
                'convenio' => (string) data_get($integration->settings, 'agreement', 'SUS'),
                'observacao' => $order->notes,
                'datahora' => $requestedAt instanceof DateTimeInterface ? $requestedAt->format('Y-m-d H:i') : null,
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
