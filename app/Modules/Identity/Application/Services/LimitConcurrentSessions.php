<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Support\Facades\DB;

final class LimitConcurrentSessions
{
    public function enforce(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $keepPrevious = max(0, (int) config('sync_sus.max_concurrent_sessions') - 1);
        $connection = DB::connection((string) config('session.connection', 'core'));
        $sessionIds = $connection->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->pluck('id');
        $expiredIds = $sessionIds->slice($keepPrevious);
        if ($expiredIds->isNotEmpty()) {
            $connection->table((string) config('session.table', 'sessions'))->whereIn('id', $expiredIds)->delete();
        }
    }
}
