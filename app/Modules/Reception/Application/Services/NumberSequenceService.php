<?php

declare(strict_types=1);

namespace App\Modules\Reception\Application\Services;

use App\Modules\Reception\Infrastructure\Eloquent\NumberSequence;
use Illuminate\Database\QueryException;

final class NumberSequenceService
{
    public function next(string $scope, string $dateKey = ''): int
    {
        try {
            $sequence = NumberSequence::query()->firstOrCreate([
                'scope' => $scope,
                'date_key' => $dateKey,
            ]);
        } catch (QueryException) {
            // Outra transação criou a sequência entre a consulta e a inserção.
            $sequence = NumberSequence::query()
                ->where('scope', $scope)
                ->where('date_key', $dateKey)
                ->firstOrFail();
        }

        $sequence = NumberSequence::query()->lockForUpdate()->findOrFail($sequence->getKey());

        $sequence->increment('current_value');
        $sequence->refresh();

        return (int) $sequence->current_value;
    }
}
