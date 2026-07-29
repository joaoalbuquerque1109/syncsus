<?php

declare(strict_types=1);

namespace App\Modules\Queues\Domain\Enums;

enum QueueEntryStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case InService = 'in_service';
    case Absent = 'absent';
    case Transferred = 'transferred';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Aguardando',
            self::Called => 'Chamado',
            self::InService => 'Em atendimento',
            self::Absent => 'Não localizado',
            self::Transferred => 'Transferido',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }
}
