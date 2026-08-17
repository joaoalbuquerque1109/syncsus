<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use App\Modules\Patients\Infrastructure\Eloquent\PatientIdentifier;

final readonly class BackfillPatientIdentifierProtection
{
    public function __construct(private PatientIdentifierProtector $protector) {}

    public function execute(bool $apply = false): int
    {
        $query = PatientIdentifier::query()->where(function ($query): void {
            $query->whereNull('encrypted_value')->orWhereNull('fingerprint');
        });
        $count = (clone $query)->count();
        if (! $apply) {
            return $count;
        }

        $query->orderBy('id')->chunkById(250, function ($identifiers): void {
            foreach ($identifiers as $identifier) {
                $identifier->forceFill($this->protector->protect(
                    $identifier->typeValue(),
                    (string) $identifier->normalized_value,
                ))->saveQuietly();
            }
        });

        return $count;
    }
}
