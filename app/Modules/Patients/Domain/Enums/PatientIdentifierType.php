<?php

declare(strict_types=1);

namespace App\Modules\Patients\Domain\Enums;

enum PatientIdentifierType: string
{
    case Cpf = 'cpf';
    case Cns = 'cns';
    case Rg = 'rg';
    case Passport = 'passport';
}
