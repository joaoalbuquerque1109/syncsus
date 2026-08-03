<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CancelLaboratoryOrderAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    public function execute(
        ExamOrder $order,
        string $reason,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): void {
        DB::transaction(function () use ($order, $reason, $user, $unit, $request): void {
            $locked = ExamOrder::query()
                ->whereKey($order->getKey())
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['order' => 'Somente requisições pendentes podem ser canceladas.']);
            }
            $hasAcceptedTransmission = $locked->laboratoryTransmissions()
                ->where('status', LaboratoryTransmissionStatus::Accepted->value)
                ->exists();
            if ($hasAcceptedTransmission) {
                throw ValidationException::withMessages([
                    'order' => 'A requisição já foi aceita pelo laboratório e não pode ser cancelada por este fluxo.',
                ]);
            }

            $locked->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->getKey(),
                'cancellation_reason' => $reason,
            ]);
            $locked->items()->update(['status' => 'cancelled']);
            $locked->laboratoryTransmissions()
                ->whereNotIn('status', [LaboratoryTransmissionStatus::Accepted->value])
                ->update([
                    'status' => LaboratoryTransmissionStatus::Cancelled->value,
                    'last_error' => 'Cancelada antes do aceite pelo laboratório.',
                ]);

            $this->audit->execute(
                'laboratory.order_cancelled',
                $request,
                $user,
                ['exam_order' => $locked->public_id, 'reason' => $reason],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );
        });
    }
}
