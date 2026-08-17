<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final readonly class RecoverStaleLaboratoryTransmissionsAction
{
    public function __construct(private RecordLaboratoryTransmissionAuditAction $audit) {}

    public function execute(): int
    {
        $ids = LaboratoryOrderTransmission::query()
            ->where('status', LaboratoryTransmissionStatus::Sending->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', now())
            ->oldest('id')
            ->limit(100)
            ->pluck('id');
        $recovered = 0;

        foreach ($ids as $id) {
            $transmission = DB::transaction(function () use ($id): ?LaboratoryOrderTransmission {
                $locked = LaboratoryOrderTransmission::query()->lockForUpdate()->find($id);
                $leaseExpiresAt = $locked?->getAttribute('lease_expires_at');
                if ($locked === null
                    || $locked->statusEnum() !== LaboratoryTransmissionStatus::Sending
                    || ! $leaseExpiresAt instanceof CarbonInterface
                    || $leaseExpiresAt->isFuture()) {
                    return null;
                }

                $locked->update([
                    'status' => LaboratoryTransmissionStatus::ManualReview,
                    'next_attempt_at' => null,
                    'worker_token' => null,
                    'sending_started_at' => null,
                    'lease_expires_at' => null,
                    'error_code' => 'sending_lease_expired',
                    'last_error' => 'O worker foi interrompido durante o envio. Confirme no Synclab antes de reenviar.',
                ]);
                $locked->attempts()
                    ->where('status', 'sending')
                    ->latest('attempt_number')
                    ->first()?->update([
                        'status' => 'manual_review',
                        'error_code' => 'sending_lease_expired',
                        'error_message' => 'Envio interrompido com resultado externo desconhecido.',
                        'finished_at' => now(),
                    ]);

                return $locked->fresh();
            });

            if ($transmission === null) {
                continue;
            }

            $this->audit->execute($transmission, 'laboratory.transmission_lease_expired', [
                'error_code' => 'sending_lease_expired',
            ]);
            $recovered++;
        }

        return $recovered;
    }
}
