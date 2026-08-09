<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Actions;

use App\Modules\Laboratory\Infrastructure\Eloquent\LaboratoryIntegration;
use Illuminate\Support\Str;

final class RotateSynclabResultTokenAction
{
    public function execute(LaboratoryIntegration $integration): string
    {
        $token = 'synclab_result_'.Str::random(64);
        $integration->update([
            'result_api_token_hash' => hash('sha256', $token),
            'result_api_token_rotated_at' => now(),
        ]);

        return $token;
    }
}
