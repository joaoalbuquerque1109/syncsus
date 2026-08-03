<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Contracts\LaboratoryProviderClient;
use App\Modules\Laboratory\Application\Exceptions\InvalidLaboratoryOrder;
use App\Modules\Laboratory\Application\Services\SynclabOrderPayloadBuilder;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryTransmissionAttempt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class SubmitLaboratoryOrderTransmissionAction
{
    public function __construct(
        private SynclabOrderPayloadBuilder $payloadBuilder,
        private LaboratoryProviderClient $client,
        private RecordLaboratoryTransmissionAuditAction $audit,
    ) {}

    public function execute(int $transmissionId): void
    {
        $transmission = LaboratoryOrderTransmission::query()
            ->with(['integration', 'order'])
            ->findOrFail($transmissionId);
        if (! $this->maySend($transmission)) {
            $this->holdForConfiguration($transmission);

            return;
        }
        if (! in_array($transmission->statusEnum(), [
            LaboratoryTransmissionStatus::Pending,
            LaboratoryTransmissionStatus::Retrying,
        ], true)) {
            return;
        }

        $externalOrderNumber = (string) $transmission->order->getKey();
        try {
            $payload = $this->payloadBuilder->build(
                $transmission->order,
                $transmission->integration,
                $externalOrderNumber,
            );
        } catch (InvalidLaboratoryOrder $exception) {
            $transmission->update([
                'status' => LaboratoryTransmissionStatus::ManualReview,
                'external_order_number' => $externalOrderNumber,
                'error_code' => 'invalid_order',
                'last_error' => $exception->getMessage(),
                'next_attempt_at' => null,
            ]);
            $this->audit->execute($transmission, 'laboratory.transmission_manual_review', [
                'error_code' => 'invalid_order',
            ]);

            return;
        }

        $requestHash = hash('sha256', json_encode($payload->toArray(), JSON_THROW_ON_ERROR));
        $attemptId = DB::transaction(function () use (
            $transmission,
            $externalOrderNumber,
            $requestHash,
        ): ?int {
            $locked = LaboratoryOrderTransmission::query()->lockForUpdate()->findOrFail($transmission->getKey());
            if (! in_array($locked->statusEnum(), [
                LaboratoryTransmissionStatus::Pending,
                LaboratoryTransmissionStatus::Retrying,
            ], true)) {
                return null;
            }
            $attemptNumber = $locked->attempt_count + 1;
            $locked->update([
                'status' => LaboratoryTransmissionStatus::Sending,
                'external_order_number' => $externalOrderNumber,
                'attempt_count' => $attemptNumber,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'request_hash' => $requestHash,
                'error_code' => null,
                'last_error' => null,
            ]);

            return (int) $locked->attempts()->create([
                'attempt_number' => $attemptNumber,
                'status' => 'sending',
                'request_hash' => $requestHash,
                'started_at' => now(),
            ])->getKey();
        });
        if ($attemptId === null) {
            return;
        }

        try {
            $result = $this->client->submitOrder($transmission->integration, $payload);
        } catch (Throwable $exception) {
            $this->markRetrying($transmissionId, $attemptId, 'connection_error', $exception->getMessage());
            $transmission->integration->update([
                'connection_status' => 'error',
                'last_connection_test_at' => now(),
                'last_error' => 'Falha de conexao ao enviar uma requisicao.',
            ]);
            $this->audit->execute($transmission, 'laboratory.transmission_retrying', [
                'attempt' => $transmission->attempt_count + 1,
                'error_code' => 'connection_error',
            ]);

            throw new RuntimeException('Falha temporaria ao acessar o Synclab.', previous: $exception);
        }

        if ($result->accepted) {
            LaboratoryOrderTransmission::query()->whereKey($transmissionId)->update([
                'status' => LaboratoryTransmissionStatus::Accepted,
                'last_http_status' => $result->httpStatus,
                'response_hash' => $result->responseHash,
                'accepted_at' => now(),
                'next_attempt_at' => null,
                'error_code' => null,
                'last_error' => null,
            ]);
            LaboratoryTransmissionAttempt::query()->whereKey($attemptId)->update([
                'status' => 'accepted',
                'http_status' => $result->httpStatus,
                'response_hash' => $result->responseHash,
                'finished_at' => now(),
            ]);
            $transmission->integration->update([
                'connection_status' => 'connected',
                'last_connection_test_at' => now(),
                'last_error' => null,
            ]);
            $this->audit->execute($transmission, 'laboratory.transmission_accepted', [
                'attempt' => $transmission->attempt_count + 1,
                'http_status' => $result->httpStatus,
            ]);

            return;
        }

        $retryable = $result->httpStatus === 429 || $result->httpStatus >= 500;
        LaboratoryOrderTransmission::query()->whereKey($transmissionId)->update([
            'status' => $retryable
                ? LaboratoryTransmissionStatus::Retrying
                : LaboratoryTransmissionStatus::Rejected,
            'last_http_status' => $result->httpStatus,
            'response_hash' => $result->responseHash,
            'next_attempt_at' => $retryable ? now()->addSeconds($this->retryDelay($transmissionId)) : null,
            'error_code' => 'http_'.$result->httpStatus,
            'last_error' => 'Synclab respondeu HTTP '.$result->httpStatus.'.',
        ]);
        LaboratoryTransmissionAttempt::query()->whereKey($attemptId)->update([
            'status' => $retryable ? 'retrying' : 'rejected',
            'http_status' => $result->httpStatus,
            'response_hash' => $result->responseHash,
            'error_code' => 'http_'.$result->httpStatus,
            'error_message' => 'Synclab respondeu HTTP '.$result->httpStatus.'.',
            'finished_at' => now(),
        ]);
        $transmission->integration->update([
            'connection_status' => 'error',
            'last_connection_test_at' => now(),
            'last_error' => 'Ultimo envio respondeu HTTP '.$result->httpStatus.'.',
        ]);
        $this->audit->execute(
            $transmission,
            $retryable ? 'laboratory.transmission_retrying' : 'laboratory.transmission_rejected',
            ['attempt' => $transmission->attempt_count + 1, 'http_status' => $result->httpStatus],
        );
        if ($retryable) {
            throw new RuntimeException('Synclab temporariamente indisponivel (HTTP '.$result->httpStatus.').');
        }
    }

    private function maySend(LaboratoryOrderTransmission $transmission): bool
    {
        return (bool) config('sync_sus.synclab.enabled')
            && $transmission->integration->is_active
            && $transmission->integration->transmission_enabled;
    }

    private function holdForConfiguration(LaboratoryOrderTransmission $transmission): void
    {
        if (! in_array($transmission->statusEnum(), [
            LaboratoryTransmissionStatus::Accepted,
            LaboratoryTransmissionStatus::Cancelled,
        ], true)) {
            $transmission->update([
                'status' => LaboratoryTransmissionStatus::AwaitingConfiguration,
                'next_attempt_at' => null,
            ]);
        }
    }

    private function markRetrying(int $transmissionId, int $attemptId, string $code, string $message): void
    {
        LaboratoryOrderTransmission::query()->whereKey($transmissionId)->update([
            'status' => LaboratoryTransmissionStatus::Retrying,
            'next_attempt_at' => now()->addSeconds($this->retryDelay($transmissionId)),
            'error_code' => $code,
            'last_error' => mb_substr($message, 0, 2000),
        ]);
        LaboratoryTransmissionAttempt::query()->whereKey($attemptId)->update([
            'status' => 'retrying',
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ]);
    }

    private function retryDelay(int $transmissionId): int
    {
        $attempt = (int) LaboratoryOrderTransmission::query()->whereKey($transmissionId)->value('attempt_count');

        return match (true) {
            $attempt <= 1 => 60,
            $attempt === 2 => 300,
            $attempt === 3 => 900,
            default => 1800,
        };
    }
}
