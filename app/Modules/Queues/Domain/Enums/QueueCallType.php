<?php

declare(strict_types=1);

namespace App\Modules\Queues\Domain\Enums;

enum QueueCallType: string
{
    case Call = 'call';
    case Recall = 'recall';
}
