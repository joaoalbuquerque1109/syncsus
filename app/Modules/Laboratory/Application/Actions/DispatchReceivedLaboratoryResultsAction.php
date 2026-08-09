<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Jobs\ProcessSynclabExamResultJob;
use App\Modules\Laboratory\Domain\Enums\LaboratoryResultIngestionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryResultIngestion;

final class DispatchReceivedLaboratoryResultsAction
{
    public function execute(): int
    {
        if (! config('sync_sus.synclab.results_enabled')) {
            return 0;
        }

        $ingestions = LaboratoryResultIngestion::query()
            ->where('status', LaboratoryResultIngestionStatus::Received)
            ->where('received_at', '<=', now()->subMinute())
            ->oldest('id')
            ->limit(100)
            ->get();
        foreach ($ingestions as $ingestion) {
            ProcessSynclabExamResultJob::dispatch((int) $ingestion->getKey())->afterCommit();
        }

        return $ingestions->count();
    }
}
