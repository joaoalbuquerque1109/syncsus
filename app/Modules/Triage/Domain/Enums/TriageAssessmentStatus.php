<?php

declare(strict_types=1);

namespace App\Modules\Triage\Domain\Enums;

enum TriageAssessmentStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Em preenchimento',
            self::Finalized => 'Finalizada',
            self::Cancelled => 'Cancelada',
        };
    }
}
