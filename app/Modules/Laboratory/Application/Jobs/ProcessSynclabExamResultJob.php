<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Jobs;

use App\Modules\Laboratory\Application\Actions\ProcessSynclabExamResultAction;
use App\Modules\Laboratory\Application\Actions\RecordLaboratoryResultAuditAction;
use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ProcessSynclabExamResultJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $ingestionId)
    {
        $this->onQueue((string) config('sync_sus.synclab.queue', 'integrations'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return (string) $this->ingestionId;
    }

    public function handle(ProcessSynclabExamResultAction $action): void
    {
        $action->execute($this->ingestionId);
    }

    public function failed(?Throwable $exception): void
    {
        $ingestion = LaboratoryResultIngestion::query()->find($this->ingestionId);
        if ($ingestion === null || $ingestion->statusEnum() !== LaboratoryResultIngestionStatus::Received) {
            return;
        }

        $ingestion->update([
            'status' => LaboratoryResultIngestionStatus::ManualReview,
            'error_code' => 'processing_deferred',
            'last_error' => mb_substr($exception?->getMessage() ?? 'Processamento interrompido.', 0, 2000),
        ]);
        app(RecordLaboratoryResultAuditAction::class)->execute(
            $ingestion,
            'laboratory.result_processing_deferred',
        );
    }
}
