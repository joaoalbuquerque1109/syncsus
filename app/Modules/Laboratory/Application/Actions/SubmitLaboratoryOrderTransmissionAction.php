<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Contracts\LaboratoryProviderClient;
use App\Modules\Laboratory\Application\Exceptions\InvalidLaboratoryOrder;
use App\Modules\Laboratory\Application\Services\SynclabOrderPayloadBuilder;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final readonly class SubmitLaboratoryOrderTransmissionAction
{
    public function __construct(
        private SynclabOrderPayloadBuilder $payloadBuilder,
        private LaboratoryProviderClient $client,
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

            return;
        }

        $claimed = DB::transaction(function () use ($transmission, $externalOrderNumber, $payload): bool {
            $locked = LaboratoryOrderTransmission::query()->lockForUpdate()->findOrFail($transmission->getKey());
            if (! in_array($locked->statusEnum(), [
                LaboratoryTransmissionStatus::Pending,
                LaboratoryTransmissionStatus::Retrying,
            ], true)) {
                return false;
            }
            $locked->update([
                'status' => LaboratoryTransmissionStatus::Sending,
                'external_order_number' => $externalOrderNumber,
                'attempt_count' => $locked->attempt_count + 1,
                'last_attempt_at' => now(),
                'next_attempt_at' => null,
                'request_hash' => hash('sha256', json_encode($payload->toArray(), JSON_THROW_ON_ERROR)),
                'error_code' => null,
                'last_error' => null,
            ]);

            return true;
        });
        if (! $claimed) {
            return;
        }

        try {
            $result = $this->client->submitOrder($transmission->integration, $payload);
        } catch (Throwable $exception) {
            $this->markRetrying($transmissionId, 'connection_error', $exception->getMessage());

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

    private function markRetrying(int $transmissionId, string $code, string $message): void
    {
        LaboratoryOrderTransmission::query()->whereKey($transmissionId)->update([
            'status' => LaboratoryTransmissionStatus::Retrying,
            'next_attempt_at' => now()->addSeconds($this->retryDelay($transmissionId)),
            'error_code' => $code,
            'last_error' => mb_substr($message, 0, 2000),
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
