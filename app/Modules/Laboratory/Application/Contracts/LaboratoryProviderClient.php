<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Contracts;

use App\Modules\Laboratory\Application\Data\LaboratoryOrderPayload;
use App\Modules\Laboratory\Application\Data\LaboratorySubmissionResult;
use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;

interface LaboratoryProviderClient
{
    public function submitOrder(
        LaboratoryIntegration $integration,
        LaboratoryOrderPayload $payload,
    ): LaboratorySubmissionResult;
}
