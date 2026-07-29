<?php

declare(strict_types=1);

namespace App\Modules\Patients\Domain\Enums;

enum PatientSex: string
{
    case Female = 'female';
    case Male = 'male';
    case Intersex = 'intersex';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Female => 'Feminino',
            self::Male => 'Masculino',
            self::Intersex => 'Intersexo',
            self::Unknown => 'Não informado',
        };
    }
}
