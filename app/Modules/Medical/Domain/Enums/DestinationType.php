<?php

declare(strict_types=1);

namespace App\Modules\Medical\Domain\Enums;

use App\Modules\Reception\Domain\Enums\EncounterStatus;

enum DestinationType: string
{
    case Discharge = 'discharge';
    case Observation = 'observation';
    case AdmissionRequest = 'admission_request';
    case Transfer = 'transfer';
    case Evasion = 'evasion';
    case Death = 'death';

    public function encounterStatus(): EncounterStatus
    {
        return match ($this) {
            self::Discharge => EncounterStatus::Discharged,
            self::Observation => EncounterStatus::UnderObservation,
            self::AdmissionRequest => EncounterStatus::AwaitingAdmission,
            self::Transfer => EncounterStatus::Transferred,
            self::Evasion => EncounterStatus::LeftWithoutNotice,
            self::Death => EncounterStatus::Deceased,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Discharge => 'Alta',
            self::Observation => 'Observação',
            self::AdmissionRequest => 'Solicitação de internação',
            self::Transfer => 'Transferência',
            self::Evasion => 'Evasão',
            self::Death => 'Óbito',
        };
    }
}
