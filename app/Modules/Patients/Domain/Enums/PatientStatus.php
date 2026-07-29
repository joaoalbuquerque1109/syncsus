<?php

declare(strict_types=1);

namespace App\Modules\Patients\Domain\Enums;

enum PatientStatus: string
{
    case Active = 'active';
    case Merged = 'merged';
    case Inactive = 'inactive';
}
