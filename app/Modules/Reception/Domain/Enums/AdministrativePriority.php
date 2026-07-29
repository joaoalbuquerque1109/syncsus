<?php

declare(strict_types=1);

namespace App\Modules\Reception\Domain\Enums;

enum AdministrativePriority: string
{
    case None = 'none';
    case Elderly = 'elderly';
    case Pregnant = 'pregnant';
    case Disabled = 'disabled';
    case ChildWithInfant = 'child_with_infant';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Sem prioridade administrativa',
            self::Elderly => 'Pessoa idosa',
            self::Pregnant => 'Gestante',
            self::Disabled => 'Pessoa com deficiência',
            self::ChildWithInfant => 'Pessoa com criança de colo',
            self::Other => 'Outra prioridade legal',
        };
    }
}
