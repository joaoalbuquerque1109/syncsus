<?php

declare(strict_types=1);

namespace App\Modules\Reception\Application\Services;

use App\Modules\Reception\Infrastructure\Eloquent\NumberSequence;
use Illuminate\Database\QueryException;

final class NumberSequenceService
{
    public function next(string $scope, string $dateKey = ''): int
    {
        $sequence = $this->findOrCreate($scope, $dateKey);
        $sequence = NumberSequence::query()->lockForUpdate()->findOrFail($sequence->getKey());

        $sequence->increment('current_value');
        $sequence->refresh();

        return (int) $sequence->current_value;
    }

    public function advanceToAtLeast(string $scope, int $minimum, string $dateKey = ''): void
    {
        $sequence = $this->findOrCreate($scope, $dateKey);
        $sequence = NumberSequence::query()->lockForUpdate()->findOrFail($sequence->getKey());

        if ((int) $sequence->current_value < $minimum) {
            $sequence->update(['current_value' => $minimum]);
        }
    }

    private function findOrCreate(string $scope, string $dateKey): NumberSequence
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

        return $sequence;
    }
}
