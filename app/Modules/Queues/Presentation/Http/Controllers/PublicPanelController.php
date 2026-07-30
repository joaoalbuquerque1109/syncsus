<?php

declare(strict_types=1);

namespace App\Modules\Queues\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Queues\Application\Services\PanelPatientNameFormatter;
use App\Modules\Queues\Domain\Enums\QueueCallType;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\QueueCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PublicPanelController extends Controller
{
    public function show(Panel $panel): View
    {
        abort_unless($panel->is_active, 404);

        return view('panels.show', ['panel' => $panel->load('healthUnit')]);
    }

    public function state(Request $request, Panel $panel, PanelPatientNameFormatter $formatter): JsonResponse
    {
        abort_unless($panel->is_active, 404);
        $validated = $request->validate(['after' => ['nullable', 'string', 'max:64']]);
        $queueIds = $this->queueIds($panel);
        $query = QueueCall::query()
            ->with(['servicePoint', 'entry.encounter.patient'])
            ->whereIn('queue_id', $queueIds);

        $after = (string) ($validated['after'] ?? '');
        $cursorFound = false;
        if ($after !== '') {
            $cursor = QueueCall::query()->where('public_id', $after)->first();
            if ($cursor !== null) {
                $cursorFound = true;
                $query->where('id', '>', $cursor->getKey())->orderBy('id');
            } else {
                $query->orderByDesc('id')->limit($panel->previousCallsCount() + 1);
            }
        } else {
            $query->orderByDesc('id')->limit($panel->previousCallsCount() + 1);
        }

        $calls = $query->get();
        if ($after === '' || ! $cursorFound) {
            $calls = $calls->reverse()->values();
        }

        $data = [];
        foreach ($calls as $call) {
            $data[] = [
                'event' => $call->public_id,
                'ticket' => $call->ticket_snapshot,
                'person_label' => $formatter->format($call->entry->encounter->patient, $panel->identificationMode()),
                'destination' => $call->servicePoint->name,
                'called_at' => $call->calledAt()->toIso8601String(),
                'is_recall' => $call->callTypeEnum() === QueueCallType::Recall,
            ];
        }

        return response()->json([
            'data' => $data,
            'meta' => [
                'panel' => $panel->name,
                'server_time' => now()->toIso8601String(),
                'poll_after_ms' => max(1, (int) config('sync_sus.panel_poll_seconds', 2)) * 1000,
            ],
        ])->withHeaders([
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    public function heartbeat(Panel $panel): JsonResponse
    {
        abort_unless($panel->is_active, 404);
        $minimumInterval = max(5, (int) config('sync_sus.panel_heartbeat_seconds', 15));
        Panel::query()
            ->whereKey($panel->getKey())
            ->where(function ($query) use ($minimumInterval): void {
                $query->whereNull('last_heartbeat_at')
                    ->orWhere('last_heartbeat_at', '<=', now()->subSeconds($minimumInterval));
            })
            ->update([
                'last_heartbeat_at' => now(),
                'heartbeat_count' => DB::raw('heartbeat_count + 1'),
                'updated_at' => now(),
            ]);

        return response()->json(['ok' => true, 'server_time' => now()->toIso8601String()])
            ->header('Cache-Control', 'no-store');
    }

    /** @return list<int> */
    private function queueIds(Panel $panel): array
    {
        $resolve = static fn (): array => $panel->queues()
            ->pluck('queues.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (! config('sync_sus.performance_cache.enabled', true)) {
            return $resolve();
        }

        return Cache::remember(
            "syncsus:unit:{$panel->health_unit_id}:panel:{$panel->getKey()}:queue-ids",
            (int) config('sync_sus.performance_cache.panel_configuration_seconds', 60),
            $resolve,
        );
    }
}
