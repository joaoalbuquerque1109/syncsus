<?php

declare(strict_types=1);

namespace App\Modules\Queues\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Application\Services\PanelPatientNameFormatter;
use App\Modules\Queues\Domain\Enums\PanelIdentificationMode;
use App\Modules\Queues\Domain\Enums\QueueCallType;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\QueueCall;
use App\Support\Tenancy\PublicLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class PublicPanelController extends Controller
{
    public function show(string $panel, PublicLookupService $lookups): View
    {
        try {
            $resolvedPanel = $lookups->resolvePanel($panel);
            abort_unless($resolvedPanel->is_active, 404);
            $resolvedPanel->setRelation(
                'healthUnit',
                HealthUnit::query()->findOrFail($resolvedPanel->health_unit_id),
            );

            return view('panels.show', ['panel' => $resolvedPanel]);
        } finally {
            $lookups->reset();
        }
    }

    public function state(
        Request $request,
        string $panel,
        PanelPatientNameFormatter $formatter,
        PublicLookupService $lookups,
    ): JsonResponse {
        try {
            $panel = $lookups->resolvePanel($panel);
            abort_unless($panel->is_active, 404);
            $validated = $request->validate(['after' => ['nullable', 'string', 'max:64']]);
            $queueIds = $this->queueIds($panel);
            $query = QueueCall::query()
                ->with(['servicePoint', 'entry.encounter'])
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

            $patients = Patient::query()
                ->whereKey($calls->pluck('entry.encounter.patient_id')->filter()->unique()->all())
                ->get()
                ->keyBy(fn (Patient $patient): int => (int) $patient->getKey());

            $data = [];
            foreach ($calls as $call) {
                $patient = $patients->get((int) $call->entry->encounter->patient_id);
                abort_unless($patient instanceof Patient, 404);
                $data[] = [
                    'event' => $call->public_id,
                    'ticket' => $call->ticket_snapshot,
                    'person_label' => $formatter->format(
                        $patient,
                        PanelIdentificationMode::FullName,
                    ),
                    // Fluxo configurável por senha preservado para reativação futura:
                    // 'person_label' => $formatter->format($call->entry->encounter->patient, $panel->identificationMode()),
                    'destination' => $call->servicePoint->name,
                    'called_at' => $call->calledAt()->toIso8601String(),
                    'is_recall' => $call->callTypeEnum() === QueueCallType::Recall,
                ];
            }

            $response = response()->json([
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

            return $response;
        } finally {
            $lookups->reset();
        }
    }

    public function heartbeat(string $panel, PublicLookupService $lookups): JsonResponse
    {
        try {
            $panel = $lookups->resolvePanel($panel);
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

            $response = response()->json(['ok' => true, 'server_time' => now()->toIso8601String()])
                ->header('Cache-Control', 'no-store');

            return $response;
        } finally {
            $lookups->reset();
        }
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
