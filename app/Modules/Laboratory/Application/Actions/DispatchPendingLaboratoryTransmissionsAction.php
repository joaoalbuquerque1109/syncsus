<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Application\Jobs\SubmitLaboratoryOrderJob;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;

final readonly class DispatchPendingLaboratoryTransmissionsAction
{
    public function __construct(private RecoverStaleLaboratoryTransmissionsAction $recoverStale) {}

    public function execute(): int
    {
        if (! config('sync_sus.synclab.enabled')) {
            return 0;
        }

        $this->recoverStale->execute();

        $transmissions = LaboratoryOrderTransmission::query()
            ->with('integration')
            ->whereIn('status', [
                LaboratoryTransmissionStatus::Pending->value,
                LaboratoryTransmissionStatus::Retrying->value,
            ])
            ->where(fn ($query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->oldest('id')
            ->limit(100)
            ->get();
        $dispatched = 0;
        foreach ($transmissions as $transmission) {
            if (! $transmission->integration->is_active || ! $transmission->integration->transmission_enabled) {
                continue;
            }
            SubmitLaboratoryOrderJob::dispatch((int) $transmission->getKey())->afterCommit();
            $dispatched++;
        }

        return $dispatched;
    }
}
