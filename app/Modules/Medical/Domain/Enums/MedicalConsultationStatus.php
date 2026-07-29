<?php

declare(strict_types=1);

namespace App\Modules\Medical\Domain\Enums;

enum MedicalConsultationStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Cancelled = 'cancelled';
}
