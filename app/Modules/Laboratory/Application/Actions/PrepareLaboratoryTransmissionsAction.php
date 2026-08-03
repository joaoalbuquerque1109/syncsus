<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;

final class PrepareLaboratoryTransmissionsAction
{
    public function execute(ExamOrder $order, HealthUnit $unit): void
    {
        $integrationIds = $order->items()
            ->whereNotNull('laboratory_integration_id')
            ->distinct()
            ->pluck('laboratory_integration_id');

        foreach ($integrationIds as $integrationId) {
            $integration = LaboratoryIntegration::query()
                ->whereKey($integrationId)
                ->where('organization_id', $unit->organization_id)
                ->where('health_unit_id', $unit->getKey())
                ->firstOrFail();

            LaboratoryOrderTransmission::query()->firstOrCreate(
                [
                    'laboratory_integration_id' => $integration->getKey(),
                    'exam_order_id' => $order->getKey(),
                ],
                [
                    'organization_id' => $unit->organization_id,
                    'health_unit_id' => $unit->getKey(),
                    'idempotency_key' => "synclab:{$integration->getKey()}:{$order->public_id}:v1",
                    // The outbox is intentionally held until the external order-number and
                    // duplicate-handling contracts are confirmed with Synclab.
                    'status' => LaboratoryTransmissionStatus::AwaitingContract,
                ],
            );
        }
    }
}
