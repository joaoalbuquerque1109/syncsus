<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Jobs;

use App\Modules\Laboratory\Application\Actions\RecordLaboratoryTransmissionAuditAction;
use App\Modules\Laboratory\Application\Actions\SubmitLaboratoryOrderTransmissionAction;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class SubmitLaboratoryOrderJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $transmissionId)
    {
        $this->onQueue((string) config('sync_sus.synclab.queue', 'integrations'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function uniqueId(): string
    {
        return (string) $this->transmissionId;
    }

    public function handle(SubmitLaboratoryOrderTransmissionAction $action): void
    {
        $action->execute($this->transmissionId);
    }

    public function failed(?Throwable $exception): void
    {
        $transmission = LaboratoryOrderTransmission::query()->find($this->transmissionId);
        if ($transmission === null || in_array($transmission->statusEnum(), [
            LaboratoryTransmissionStatus::Accepted,
            LaboratoryTransmissionStatus::Rejected,
            LaboratoryTransmissionStatus::Cancelled,
        ], true)) {
            return;
        }

        $message = mb_substr($exception?->getMessage() ?? 'Tentativas esgotadas.', 0, 2000);
        $transmission->update([
            'status' => LaboratoryTransmissionStatus::ManualReview,
            'next_attempt_at' => null,
            'error_code' => 'attempts_exhausted',
            'last_error' => $message,
        ]);
        $transmission->attempts()->first()?->update([
            'status' => 'manual_review',
            'error_code' => 'attempts_exhausted',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
        app(RecordLaboratoryTransmissionAuditAction::class)->execute(
            $transmission,
            'laboratory.transmission_manual_review',
            ['error_code' => 'attempts_exhausted', 'attempts' => $transmission->attempt_count],
        );
    }
}
