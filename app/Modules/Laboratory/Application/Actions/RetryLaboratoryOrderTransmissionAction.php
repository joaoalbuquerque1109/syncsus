<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Jobs\SubmitLaboratoryOrderJob;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use App\Support\Tenancy\TenantConnectionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RetryLaboratoryOrderTransmissionAction
{
    public function __construct(
        private RecordAuditEventAction $audit,
        private TenantConnectionManager $connectionManager,
    ) {}

    public function execute(
        ExamOrder $order,
        HealthUnit $unit,
        User $user,
        Request $request,
    ): LaboratoryOrderTransmission {
        $transmission = DB::transaction(function () use ($order, $unit, $user, $request): LaboratoryOrderTransmission {
            $lockedOrder = ExamOrder::query()
                ->whereKey($order->getKey())
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedOrder->status !== 'pending') {
                throw ValidationException::withMessages([
                    'transmission' => 'Somente requisições pendentes podem ser reenviadas.',
                ]);
            }

            $transmission = $lockedOrder->laboratoryTransmissions()
                ->with('integration')
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($transmission->statusEnum(), [
                LaboratoryTransmissionStatus::AwaitingConfiguration,
                LaboratoryTransmissionStatus::Retrying,
                LaboratoryTransmissionStatus::Rejected,
                LaboratoryTransmissionStatus::ManualReview,
            ], true)) {
                throw ValidationException::withMessages([
                    'transmission' => 'O status atual não permite um reenvio manual.',
                ]);
            }
            if (! config('sync_sus.synclab.enabled')
                || ! $transmission->integration->is_active
                || ! $transmission->integration->transmission_enabled) {
                throw ValidationException::withMessages([
                    'transmission' => 'Configure e habilite a integração Synclab desta unidade antes do reenvio.',
                ]);
            }

            $transmission->update([
                'status' => LaboratoryTransmissionStatus::Pending,
                'next_attempt_at' => null,
                'worker_token' => null,
                'sending_started_at' => null,
                'lease_expires_at' => null,
                'error_code' => null,
                'last_error' => null,
            ]);
            $this->audit->execute(
                'laboratory.transmission_manual_retry',
                $request,
                $user,
                [
                    'exam_order' => $lockedOrder->public_id,
                    'transmission' => $transmission->public_id,
                    'previous_attempts' => $transmission->attempt_count,
                ],
                (int) $unit->getKey(),
                (int) $lockedOrder->encounter->patient_id,
                (int) $lockedOrder->encounter_id,
            );

            return $transmission;
        });

        SubmitLaboratoryOrderJob::dispatch(
            (int) $transmission->getKey(),
            (string) $unit->public_id,
            $this->connectionManager->connectionName($unit),
        )->afterCommit();

        return $transmission;
    }
}
