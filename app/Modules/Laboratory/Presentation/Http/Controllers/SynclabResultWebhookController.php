<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Laboratory\Application\Actions\RecordLaboratoryResultAuditAction;
use App\Modules\Laboratory\Application\Jobs\ProcessSynclabExamResultJob;
use App\Modules\Laboratory\Application\Services\SynclabResultPayloadHasher;
use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class SynclabResultWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        SynclabResultPayloadHasher $hasher,
        RecordLaboratoryResultAuditAction $audit,
    ): JsonResponse {
        $integration = $request->attributes->get('laboratory_integration');
        abort_unless($integration instanceof LaboratoryIntegration, 401);
        $payload = $request->all();
        $contentHash = $hasher->contentHash($payload);
        $externalOrderNumber = trim((string) ($payload['codigo_pedido'] ?? ''));
        $externalExamCode = trim((string) ($payload['codigo_exame'] ?? ''));
        $externalResultReference = trim((string) ($payload['referencia_resultado'] ?? '')) ?: null;
        $idempotencyKey = $hasher->idempotencyKey(
            $externalOrderNumber,
            $externalExamCode,
            $externalResultReference,
            $contentHash,
        );
        $ingestion = LaboratoryResultIngestion::query()->createOrFirst(
            [
                'laboratory_integration_id' => $integration->getKey(),
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'external_order_number' => $externalOrderNumber ?: null,
                'external_exam_code' => $externalExamCode ?: null,
                'external_result_reference' => $externalResultReference,
                'content_hash' => $contentHash,
                'payload' => $payload,
                'status' => LaboratoryResultIngestionStatus::Received,
                'received_at' => now(),
            ],
        );
        if (! $ingestion->wasRecentlyCreated) {
            if (! hash_equals((string) $ingestion->content_hash, $contentHash)) {
                $conflictKey = hash('sha256', $idempotencyKey.'|conflict|'.$contentHash);
                $conflict = LaboratoryResultIngestion::query()->createOrFirst(
                    [
                        'laboratory_integration_id' => $integration->getKey(),
                        'idempotency_key' => $conflictKey,
                    ],
                    [
                        'external_order_number' => $externalOrderNumber ?: null,
                        'external_exam_code' => $externalExamCode ?: null,
                        'external_result_reference' => $externalResultReference,
                        'content_hash' => $contentHash,
                        'payload' => $payload,
                        'status' => LaboratoryResultIngestionStatus::ManualReview,
                        'error_code' => 'idempotency_conflict',
                        'last_error' => 'A mesma referência externa foi reenviada com conteúdo diferente.',
                        'received_at' => now(),
                        'processed_at' => now(),
                    ],
                );
                if ($conflict->wasRecentlyCreated) {
                    $audit->execute(
                        $conflict,
                        'laboratory.result_manual_review',
                        ['error_code' => 'idempotency_conflict'],
                    );
                } else {
                    $conflict->increment('duplicate_count');
                    $conflict->update(['last_duplicate_at' => now()]);
                }

                return response()->json([
                    'status' => 'conflict',
                    'ingestion_id' => $conflict->public_id,
                ], 409);
            }

            $ingestion->increment('duplicate_count');
            $ingestion->update(['last_duplicate_at' => now()]);
            $audit->execute($ingestion, 'laboratory.result_replayed');

            return response()->json([
                'status' => 'duplicate',
                'ingestion_id' => $ingestion->public_id,
            ], 202);
        }

        $validationPayload = $payload;
        foreach (['codigo_pedido', 'codigo_exame', 'referencia_resultado'] as $identifierField) {
            if (isset($validationPayload[$identifierField]) && is_int($validationPayload[$identifierField])) {
                $validationPayload[$identifierField] = (string) $validationPayload[$identifierField];
            }
        }
        $validator = Validator::make($validationPayload, [
            'codigo_pedido' => ['required', 'string', 'max:128'],
            'codigo_exame' => ['required', 'string', 'max:128'],
            'resultado' => ['required', 'string', 'max:20000'],
            'conclusao' => ['nullable', 'string', 'max:10000'],
            'observacoes' => ['nullable', 'string', 'max:10000'],
            'data_resultado' => ['required', 'date'],
            'referencia_resultado' => ['nullable', 'string', 'max:191'],
        ]);
        if ($validator->fails()) {
            $ingestion->update([
                'status' => LaboratoryResultIngestionStatus::Rejected,
                'validation_errors' => $validator->errors()->toArray(),
                'error_code' => 'invalid_payload',
                'last_error' => 'O payload de resultado não atende ao contrato.',
                'processed_at' => now(),
            ]);
            $audit->execute(
                $ingestion,
                'laboratory.result_rejected',
                ['error_code' => 'invalid_payload'],
            );

            return response()->json([
                'status' => 'rejected',
                'ingestion_id' => $ingestion->public_id,
                'errors' => $validator->errors(),
            ], 422);
        }

        $audit->execute($ingestion, 'laboratory.result_received');
        ProcessSynclabExamResultJob::dispatch((int) $ingestion->getKey())->afterCommit();

        return response()->json([
            'status' => 'received',
            'ingestion_id' => $ingestion->public_id,
        ], 202);
    }
}
