<?php

declare(strict_types=1);

namespace App\Modules\Patients\Application\Services;

use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientNumberSequence;

final class PatientMedicalRecordNumberService
{
    private const SCOPE = 'patient_mrn';

    public function next(): string
    {
        while (true) {
            $sequence = PatientNumberSequence::query()->firstOrCreate([
                'scope' => self::SCOPE,
                'date_key' => '',
            ]);
            $sequence = PatientNumberSequence::query()->lockForUpdate()->findOrFail($sequence->getKey());
            $sequence->increment('current_value');
            $number = (int) $sequence->current_value;
            $medicalRecordNumber = sprintf('P%08d', $number);

            if (! Patient::query()->where('medical_record_number', $medicalRecordNumber)->exists()) {
                return $medicalRecordNumber;
            }

            $sequence->update(['current_value' => max($number, $this->highestAssignedNumber())]);
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
