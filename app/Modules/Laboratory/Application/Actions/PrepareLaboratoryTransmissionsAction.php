<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Laboratory\Application\Services\SynclabContractReadiness;
use App\Modules\Laboratory\Domain\Enums\LaboratoryTransmissionStatus;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryOrderTransmission;
use App\Modules\Medical\Infrastructure\Eloquent\ExamOrder;

final class PrepareLaboratoryTransmissionsAction
{
    public function __construct(private readonly SynclabContractReadiness $contractReadiness) {}

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
                    'status' => $this->contractReadiness->allowsTransmission()
                        ? LaboratoryTransmissionStatus::Pending
                        : LaboratoryTransmissionStatus::AwaitingContract,
                ],
            );
        }
    }
}
