<?php

declare(strict_types=1);

namespace App\Modules\Queues\Application\Services;

use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueSequence;
use Illuminate\Database\QueryException;

final class QueueTicketService
{
    public function next(Queue $queue): string
    {
        $date = today()->toDateString();

        try {
            $sequence = QueueSequence::query()->firstOrCreate([
                'queue_id' => $queue->getKey(),
                'sequence_date' => $date,
            ]);
        } catch (QueryException) {
            // Outra recepção iniciou a sequência diária em paralelo.
            $sequence = QueueSequence::query()
                ->where('queue_id', $queue->getKey())
                ->whereDate('sequence_date', $date)
                ->firstOrFail();
        }

        $sequence = QueueSequence::query()->lockForUpdate()->findOrFail($sequence->getKey());

        $sequence->increment('current_value');
        $sequence->refresh();

        $length = max(1, min(8, (int) $queue->ticket_length));

        return sprintf('%s%0'.$length.'d', mb_strtoupper((string) $queue->prefix), $sequence->current_value);
    }
}
