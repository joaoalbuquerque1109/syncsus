<?php

declare(strict_types=1);

namespace App\Modules\Queues\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Application\Services\QueueVisibilityService;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Panel;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class QueueController extends Controller
{
    public function index(Request $request, QueueVisibilityService $visibility): View
    {
        $unit = $request->attributes->get('active_health_unit');
        $user = $request->user();
        abort_unless($unit instanceof HealthUnit && $user instanceof User, 403);
        $queues = $visibility->apply(Queue::query(), $user)
            ->with('department')
            ->where('health_unit_id', $unit->getKey())
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();
        $queues->each(function (Queue $queue) use ($user, $visibility): void {
            $queue->setRelation('servicePoints', $visibility->servicePointsFor($queue, $user));
        });
        $selected = $request->filled('queue')
            ? $queues->firstWhere('public_id', $request->query('queue'))
            : $queues->first();
        if ($request->filled('queue') && $selected === null) {
            abort(404);
        }

        return view('queues.index', [
            'queues' => $queues,
            'selectedQueue' => $selected,
            'panels' => Panel::query()->where('health_unit_id', $unit->getKey())->where('is_active', true)->get(),
            'restrictedOperationalView' => ! $visibility->hasBroadAccess($user),
        ]);
    }

    public function entries(Request $request, Queue $queue, QueueVisibilityService $visibility): JsonResponse
    {
        $unit = $request->attributes->get('active_health_unit');
        $user = $request->user();
        abort_unless($unit instanceof HealthUnit && $user instanceof User && $queue->health_unit_id === $unit->getKey(), 404);
        $visibility->ensureCanAccess($queue, $user);
        $queue->loadMissing('department');
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'in:waiting,called,in_service,absent'],
        ]);

        $query = QueueEntry::query()
            ->where('queue_id', $queue->getKey())
            ->with([
                'servicePoint', 'assignedUser',
                'encounter.patient.identifiers', 'encounter.arrivalMethod', 'encounter.riskLevel',
                'encounter.triageAssessment', 'encounter.medicalConsultation',
            ]);
        $visibility->applyEntryScope($query, $queue, $user);
        if (filled($validated['status'] ?? null)) {
            $query->where('status', $validated['status']);
        } else {
            $query->whereIn('status', [
                QueueEntryStatus::Waiting,
                QueueEntryStatus::Called,
                QueueEntryStatus::InService,
                QueueEntryStatus::Absent,
            ]);
        }
        $term = trim((string) ($validated['q'] ?? ''));
        if ($term !== '') {
            $normalized = NormalizesBrazilianData::name($term);
            $digits = NormalizesBrazilianData::digits($term);
            $query->where(function ($query) use ($term, $normalized, $digits): void {
                $query->where('ticket_number', 'like', "%{$term}%")
                    ->orWhereHas('encounter.patient', function ($query) use ($normalized, $digits): void {
                        $query->where('normalized_name', 'like', "%{$normalized}%")
                            ->orWhere('medical_record_number', 'like', "%{$normalized}%");
                        if ($digits !== null) {
                            $query->orWhereHas('identifiers', fn ($query) => $query->where('normalized_value', 'like', "%{$digits}%"));
                        }
                    });
            });
        }

        $entries = $query
            ->orderByDesc('priority_weight')
            ->orderBy('entered_at')
            ->limit(100)
            ->get();
        $data = [];
        foreach ($entries as $entry) {
            $data[] = $this->serialize($entry, $user, (string) $queue->department->type);
        }

        return response()->json([
            'data' => $data,
            'meta' => ['count' => count($data), 'server_time' => now()->toIso8601String()],
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, bool|int|string|null> */
    private function serialize(QueueEntry $entry, User $user, string $departmentType): array
    {
        $patient = $entry->encounter->patient;
        $editUrl = $this->activeRecordEditUrl($entry, $user, $departmentType);

        return [
            'public_id' => $entry->public_id,
            'version' => $entry->version(),
            'ticket' => $entry->ticket_number,
            'patient' => $patient->displayName(),
            'medical_record_number' => $patient->medical_record_number,
            'age' => $patient->ageYears(),
            'arrival_method' => $entry->encounter->arrivalMethod->name,
            'administrative_priority' => $entry->encounter->administrativePriorityEnum()->label(),
            'risk' => $entry->encounter->riskLevel?->name,
            'entered_at' => $entry->enteredAt()->format('d/m/Y H:i'),
            'waiting_minutes' => max(0, (int) $entry->enteredAt()->diffInMinutes(now())),
            'call_count' => (int) $entry->call_count,
            'status' => $entry->statusEnum()->value,
            'status_label' => $entry->statusEnum()->label(),
            'service_point' => $entry->servicePoint?->name,
            'assigned_user' => $entry->assignedUser?->name,
            'can_call' => in_array($entry->statusEnum(), [QueueEntryStatus::Waiting, QueueEntryStatus::Absent], true),
            'can_recall' => $entry->statusEnum() === QueueEntryStatus::Called,
            'can_start' => $entry->statusEnum() === QueueEntryStatus::Called,
            'can_absent' => $entry->statusEnum() === QueueEntryStatus::Called,
            'can_return' => $entry->statusEnum() === QueueEntryStatus::Absent,
            'can_transfer' => in_array($entry->statusEnum(), [QueueEntryStatus::Waiting, QueueEntryStatus::Called, QueueEntryStatus::Absent], true),
            'can_edit' => $editUrl !== null,
            'edit_url' => $editUrl,
        ];
    }

    private function activeRecordEditUrl(QueueEntry $entry, User $user, string $departmentType): ?string
    {
        if ($entry->statusEnum() !== QueueEntryStatus::InService) {
            return null;
        }

        if ($departmentType === 'triage') {
            $triage = $entry->encounter->triageAssessment;
            if ($triage === null
                || $triage->queue_entry_id !== $entry->getKey()
                || $triage->statusEnum()->value !== 'draft'
                || ! $user->canManageClinicalRecordOwnedBy($triage->professional_id)) {
                return null;
            }

            return route('triage.show', $triage);
        }

        if ($departmentType === 'medical') {
            $consultation = $entry->encounter->medicalConsultation;
            if ($consultation === null
                || $consultation->queue_entry_id !== $entry->getKey()
                || $consultation->statusEnum()->value !== 'draft'
                || ! $user->canManageClinicalRecordOwnedBy($consultation->professional_id)) {
                return null;
            }

            return route('medical.show', $consultation);
        }

        return null;
    }
}
