<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

final class SynclabContractReadiness
{
    /** @return list<string> */
    public function blockingDecisions(): array
    {
        $pending = config('synclab_contract.pending', []);
        if (! is_array($pending)) {
            return ['invalid_contract_configuration'];
        }

        return array_values(array_keys(array_filter(
            $pending,
            static fn (mixed $value): bool => $value === null || $value === '',
        )));
    }

    public function allowsTransmission(): bool
    {
        return $this->blockingDecisions() === [];
    }
}
