<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Services\SynclabResultPayloadHasher;
use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrderItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final readonly class ProcessSynclabExamResultAction
{
    public function __construct(
        private SynclabResultPayloadHasher $hasher,
        private RecordLaboratoryResultAuditAction $audit,
    ) {}

    public function execute(int $ingestionId): void
    {
        DB::transaction(function () use ($ingestionId): void {
            $ingestion = LaboratoryResultIngestion::query()->lockForUpdate()->find($ingestionId);
            if ($ingestion === null || $ingestion->statusEnum() !== LaboratoryResultIngestionStatus::Received) {
                return;
            }

            $payload = $ingestion->payloadData();
            $transmissions = LaboratoryOrderTransmission::query()
                ->with(['integration', 'order.encounter'])
                ->where('laboratory_integration_id', $ingestion->laboratory_integration_id)
                ->where('external_order_number', $ingestion->external_order_number)
                ->lockForUpdate()
                ->get();
            if ($transmissions->count() !== 1) {
                $this->manualReview(
                    $ingestion,
                    $transmissions->isEmpty() ? 'order_not_found' : 'ambiguous_order',
                    'Não foi possível resolver uma transmissão única pelo número externo e pela integração.',
                );

                return;
            }

            $transmission = $transmissions->sole();
            $ingestion->laboratory_order_transmission_id = $transmission->getKey();
            if ($transmission->statusEnum() !== LaboratoryTransmissionStatus::Accepted) {
                $this->manualReview(
                    $ingestion,
                    'transmission_not_accepted',
                    'A transmissão correspondente ainda não está aceita.',
                );

                return;
            }

            $order = $transmission->order;
            $integration = $transmission->integration;
            if ((int) $transmission->organization_id !== (int) $integration->organization_id
                || (int) $transmission->health_unit_id !== (int) $integration->health_unit_id
                || (int) $order->organization_id !== (int) $transmission->organization_id
                || (int) $order->health_unit_id !== (int) $transmission->health_unit_id) {
                $this->manualReview(
                    $ingestion,
                    'tenant_mismatch',
                    'A cadeia persistida de organização e unidade é inconsistente.',
                );

                return;
            }

            $items = ExamOrderItem::query()
                ->where('exam_order_id', $order->getKey())
                ->where('laboratory_integration_id', $integration->getKey())
                ->where('external_exam_code', $ingestion->external_exam_code)
                ->lockForUpdate()
                ->get();
            if ($items->count() !== 1) {
                $this->manualReview(
                    $ingestion,
                    $items->isEmpty() ? 'item_not_found' : 'ambiguous_item',
                    'Não foi possível resolver um item único pelo código externo do exame.',
                );

                return;
            }

            $item = $items->sole();
            $ingestion->exam_order_item_id = $item->getKey();
            $resultContent = [
                'resultado' => $payload['resultado'],
                'conclusao' => $payload['conclusao'] ?? null,
                'observacoes' => $payload['observacoes'] ?? null,
                'data_resultado' => $payload['data_resultado'],
            ];
            $resultHash = $this->hasher->contentHash($resultContent);
            $existingResult = $item->result()->lockForUpdate()->first();
            if ($existingResult !== null) {
                if (hash_equals((string) $existingResult->content_hash, $resultHash)) {
                    $ingestion->update([
                        'status' => LaboratoryResultIngestionStatus::Duplicate,
                        'processed_at' => now(),
                        'error_code' => null,
                        'last_error' => null,
                    ]);
                    $this->audit->execute($ingestion, 'laboratory.result_duplicate');

                    return;
                }

                $this->manualReview(
                    $ingestion,
                    'result_conflict',
                    'O item já possui um resultado diferente e não foi sobrescrito.',
                );

                return;
            }

            $item->result()->create([
                'source' => 'synclab',
                'laboratory_result_ingestion_id' => $ingestion->getKey(),
                'result_text' => $payload['resultado'],
                'conclusion' => $payload['conclusao'] ?? null,
                'recorded_by' => null,
                'resulted_at' => CarbonImmutable::parse((string) $payload['data_resultado']),
                'notes' => $payload['observacoes'] ?? null,
                'content_hash' => $resultHash,
            ]);
            $item->update(['status' => 'resulted']);
            $ingestion->update([
                'status' => LaboratoryResultIngestionStatus::Applied,
                'processed_at' => now(),
                'error_code' => null,
                'last_error' => null,
            ]);
            $this->audit->execute($ingestion, 'laboratory.result_applied');
        });
    }

    private function manualReview(
        LaboratoryResultIngestion $ingestion,
        string $errorCode,
        string $message,
    ): void {
        $ingestion->update([
            'laboratory_order_transmission_id' => $ingestion->laboratory_order_transmission_id,
            'exam_order_item_id' => $ingestion->exam_order_item_id,
            'status' => LaboratoryResultIngestionStatus::ManualReview,
            'error_code' => $errorCode,
            'last_error' => $message,
            'processed_at' => now(),
        ]);
        $this->audit->execute(
            $ingestion,
            'laboratory.result_manual_review',
            ['error_code' => $errorCode],
        );
    }
}
