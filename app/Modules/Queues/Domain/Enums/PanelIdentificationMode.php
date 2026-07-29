<?php

declare(strict_types=1);

namespace App\Modules\Queues\Domain\Enums;

enum PanelIdentificationMode: string
{
    case TicketOnly = 'ticket_only';
    case FirstNameInitial = 'first_name_initial';
    case SocialFirstInitial = 'social_first_initial';
    case FirstAndLast = 'first_and_last';
    case FullName = 'full_name';

    public function label(): string
    {
        return match ($this) {
            self::TicketOnly => 'Apenas senha',
            self::FirstNameInitial => 'Primeiro nome e inicial',
            self::SocialFirstInitial => 'Nome social e inicial',
            self::FirstAndLast => 'Primeiro e último nome',
            self::FullName => 'Nome completo',
        };
    }
}
