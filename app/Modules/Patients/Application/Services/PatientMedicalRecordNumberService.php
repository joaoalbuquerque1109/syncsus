<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Reception\Application\Services\NumberSequenceService;

final readonly class PatientMedicalRecordNumberService
{
    private const SCOPE = 'patient_mrn';

    public function __construct(private NumberSequenceService $sequences) {}

    public function next(): string
    {
        while (true) {
            $number = $this->sequences->next(self::SCOPE);
            $medicalRecordNumber = sprintf('P%08d', $number);

            if (! Patient::query()->where('medical_record_number', $medicalRecordNumber)->exists()) {
                return $medicalRecordNumber;
            }

            $this->sequences->advanceToAtLeast(
                self::SCOPE,
                max($number, $this->highestAssignedNumber()),
            );
        }
    }

    private function highestAssignedNumber(): int
    {
        return Patient::query()
            ->whereBetween('medical_record_number', ['P00000000', 'P99999999'])
            ->pluck('medical_record_number')
            ->reduce(static function (int $highest, string $medicalRecordNumber): int {
                if (preg_match('/^P(\d{8})$/', $medicalRecordNumber, $matches) !== 1) {
                    return $highest;
                }

                return max($highest, (int) $matches[1]);
            }, 0);
    }
}
