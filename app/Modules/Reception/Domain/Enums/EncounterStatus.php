<?php

declare(strict_types=1);

namespace App\Modules\Reception\Domain\Enums;

enum EncounterStatus: string
{
    case Opened = 'opened';
    case WaitingTriage = 'waiting_triage';
    case CalledToTriage = 'called_to_triage';
    case InTriage = 'in_triage';
    case WaitingMedical = 'waiting_medical';
    case CalledToMedical = 'called_to_medical';
    case InMedicalCare = 'in_medical_care';
    case WaitingExam = 'waiting_exam';
    case WaitingProcedure = 'waiting_procedure';
    case UnderObservation = 'under_observation';
    case AwaitingAdmission = 'awaiting_admission';
    case Admitted = 'admitted';
    case AwaitingTransfer = 'awaiting_transfer';
    case Discharged = 'discharged';
    case Transferred = 'transferred';
    case LeftWithoutNotice = 'left_without_notice';
    case Deceased = 'deceased';
    case Cancelled = 'cancelled';

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Admitted,
            self::Discharged,
            self::Transferred,
            self::LeftWithoutNotice,
            self::Deceased,
            self::Cancelled,
        ], true);
    }
}
