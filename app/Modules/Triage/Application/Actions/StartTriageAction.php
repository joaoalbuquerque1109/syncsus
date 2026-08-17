<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Queues\Application\Services\QueueVisibilityService;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class StartTriageAction
{
    public function __construct(
        private RecordAuditEventAction $audit,
        private QueueVisibilityService $visibility,
    ) {}

    public function execute(
        QueueEntry $entry,
        int $expectedVersion,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): TriageAssessment {
        return DB::transaction(function () use ($entry, $expectedVersion, $user, $unit, $request): TriageAssessment {
            $locked = QueueEntry::query()
                ->with(['queue.department', 'servicePoint', 'encounter.triageAssessment'])
                ->whereKey($entry->getKey())
                ->whereHas('queue', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $locked->queue->department->type !== 'triage') {
                throw ValidationException::withMessages(['entry' => 'A entrada selecionada não pertence a uma fila de triagem.']);
            }
            $this->visibility->ensureCanAccess($locked->queue, $user);
            $this->visibility->ensureCanAccessEntry($locked, $user);
            if ($locked->version() !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'A fila foi atualizada. Atualize a tela antes de iniciar a triagem.']);
            }

            $existing = $locked->encounter->triageAssessment;
            if ($existing instanceof TriageAssessment) {
                if ($existing->professional_id !== $user->getKey()) {
                    $name = User::query()->whereKey($existing->professional_id)->value('name') ?? 'outro profissional';
                    throw ValidationException::withMessages([
                        'entry' => "Esta triagem já foi iniciada por {$name}. Atualize a fila.",
                    ]);
                }

                return $existing;
            }

            if (! in_array($locked->statusEnum(), [QueueEntryStatus::Called, QueueEntryStatus::InService], true)) {
                throw ValidationException::withMessages([
                    'status' => "A senha {$locked->ticket_number} precisa estar chamada antes de iniciar a triagem.",
                ]);
            }
            if ($locked->statusEnum() === QueueEntryStatus::InService && $locked->assigned_user_id !== $user->getKey()) {
                $name = User::query()->whereKey($locked->assigned_user_id)->value('name') ?? 'outro profissional';
                throw ValidationException::withMessages([
                    'entry' => "Este paciente já está em atendimento por {$name}. Atualize a fila.",
                ]);
            }

            $now = now();
            if ($locked->statusEnum() === QueueEntryStatus::Called) {
                $locked->update([
                    'status' => QueueEntryStatus::InService,
                    'assigned_user_id' => $user->getKey(),
                    'service_started_at' => $now,
                    'lock_version' => $locked->version() + 1,
                ]);
                $locked->history()->create([
                    'from_status' => QueueEntryStatus::Called,
                    'to_status' => QueueEntryStatus::InService,
                    'action' => 'triage_started',
                    'service_point_id' => $locked->service_point_id,
                    'performed_by' => $user->getKey(),
                    'occurred_at' => $now,
                ]);
            }

            $encounter = $locked->encounter;
            $fromStatus = $encounter->currentStatusEnum();
            $encounter->update([
                'current_status' => EncounterStatus::InTriage,
                'triage_started_at' => $encounter->triage_started_at ?? $now,
                'current_room_id' => $locked->servicePoint?->room_id,
                'lock_version' => (int) $encounter->lock_version + 1,
            ]);
            if ($fromStatus !== EncounterStatus::InTriage) {
                $encounter->statusHistory()->create([
                    'from_status' => $fromStatus,
                    'to_status' => EncounterStatus::InTriage,
                    'reason' => 'Triagem iniciada',
                    'metadata' => ['queue_entry' => $locked->public_id],
                    'changed_by' => $user->getKey(),
                    'changed_at' => $now,
                ]);
            }

            $assessment = TriageAssessment::query()->create([
                'encounter_id' => $encounter->getKey(),
                'queue_entry_id' => $locked->getKey(),
                'professional_id' => $user->getKey(),
                'service_point_id' => $locked->service_point_id,
                'status' => TriageAssessmentStatus::Draft,
                'started_at' => $now,
            ]);
            $this->audit->execute(
                'triage.started',
                $request,
                $user,
                ['triage' => $assessment->public_id, 'ticket' => $locked->ticket_number],
                (int) $unit->getKey(),
                (int) $encounter->patient_id,
                (int) $encounter->getKey(),
            );

            return $assessment;
        });
    }
}
