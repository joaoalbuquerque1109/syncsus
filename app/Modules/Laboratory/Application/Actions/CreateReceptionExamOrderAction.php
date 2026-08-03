<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryExam;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Modules\Patients\Domain\Enums\PatientIdentifierType;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final readonly class CreateReceptionExamOrderAction
{
    public function __construct(
        private PrepareLaboratoryTransmissionsAction $prepareTransmissions,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        Encounter $encounter,
        array $data,
        User $creator,
        HealthUnit $unit,
        Request $request,
    ): ?ExamOrder {
        if (! ($data['request_exams'] ?? false)) {
            return null;
        }

        $this->ensurePatientIdentification($encounter);
        $requester = $this->requester((int) $data['exam_requester_id'], $unit);
        $exams = $this->exams((array) $data['exam_ids'], $unit);
        $order = ExamOrder::query()->create([
            'organization_id' => $unit->organization_id,
            'health_unit_id' => $unit->getKey(),
            'encounter_id' => $encounter->getKey(),
            'medical_consultation_id' => null,
            'requested_by' => $requester->user_id,
            'created_by' => $creator->getKey(),
            'origin' => 'reception',
            'status' => 'pending',
            'priority' => $data['exam_priority'],
            'clinical_indication' => $data['exam_clinical_indication'],
            'notes' => $data['exam_notes'] ?? null,
            'requested_at' => now(),
        ]);

        foreach ((array) $data['exam_ids'] as $examId) {
            $exam = $exams->get((int) $examId);
            if (! $exam instanceof LaboratoryExam) {
                continue;
            }
            $order->items()->create([
                'laboratory_integration_id' => $exam->laboratory_integration_id,
                'laboratory_exam_id' => $exam->getKey(),
                'internal_code' => $exam->external_code,
                'external_exam_code' => $exam->external_code,
                'catalog_version' => $exam->source_version,
                'laboratory_snapshot' => [
                    'external_code' => $exam->external_code,
                    'name' => $exam->name,
                    'acronym' => $exam->acronym,
                    'collection_instructions' => $exam->collection_instructions,
                ],
                'exam_name' => $exam->name,
                'group' => 'laboratory',
                'priority' => $data['exam_priority'],
                'laterality' => 'not_applicable',
                'preparation' => $exam->collection_instructions,
                'status' => 'requested',
            ]);
        }

        $this->prepareTransmissions->execute($order, $unit);
        $this->audit->execute(
            'laboratory.order_created_at_reception',
            $request,
            $creator,
            [
                'exam_order' => $order->public_id,
                'requester' => $requester->public_id,
                'item_count' => $exams->count(),
            ],
            (int) $unit->getKey(),
            (int) $encounter->patient_id,
            (int) $encounter->getKey(),
        );

        return $order->load(['items', 'laboratoryTransmissions']);
    }

    private function ensurePatientIdentification(Encounter $encounter): void
    {
        $hasOfficialIdentifier = $encounter->patient->identifiers()
            ->whereIn('type', [PatientIdentifierType::Cpf->value, PatientIdentifierType::Cns->value])
            ->exists();
        if (! $hasOfficialIdentifier) {
            throw ValidationException::withMessages([
                'request_exams' => 'Para solicitar exames, o paciente precisa possuir CPF ou Cartão Nacional de Saúde.',
            ]);
        }
    }

    private function requester(int $userId, HealthUnit $unit): HealthProfessional
    {
        $requester = HealthProfessional::query()
            ->where('organization_id', $unit->organization_id)
            ->where('user_id', $userId)
            ->where('profession_type', 'doctor')
            ->where('is_active', true)
            ->whereHas('healthUnits', fn ($query) => $query->whereKey($unit->getKey()))
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->first();
        if ($requester === null) {
            throw ValidationException::withMessages([
                'exam_requester_id' => 'Selecione um médico solicitante ativo nesta unidade.',
            ]);
        }

        return $requester;
    }

    /** @param list<mixed> $ids
     * @return Collection<int, LaboratoryExam>
     */
    private function exams(array $ids, HealthUnit $unit): Collection
    {
        $uniqueIds = collect($ids)->map(fn (mixed $id): int => (int) $id)->unique()->values();
        $exams = LaboratoryExam::query()
            ->whereIn('id', $uniqueIds)
            ->where('is_active', true)
            ->whereHas('integration', fn ($query) => $query
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->where('is_active', true))
            ->get()
            ->keyBy(fn (LaboratoryExam $exam): int => (int) $exam->getKey());
        if ($exams->count() !== $uniqueIds->count()) {
            throw ValidationException::withMessages([
                'exam_ids' => 'Um ou mais exames não estão ativos na tabela desta unidade.',
            ]);
        }

        return $exams;
    }
}
