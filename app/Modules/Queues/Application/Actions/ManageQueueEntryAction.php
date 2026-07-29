<?php

declare(strict_types=1);

namespace App\Modules\Queues\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\ServicePoint;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Professionals\Application\Services\MedicalDutyService;
use App\Modules\Queues\Application\Services\QueueTicketService;
use App\Modules\Queues\Application\Services\QueueVisibilityService;
use App\Modules\Queues\Domain\Enums\QueueCallType;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Queues\Infrastructure\Eloquent\QueueTransfer;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class ManageQueueEntryAction
{
    public function __construct(
        private RecordAuditEventAction $audit,
        private QueueTicketService $tickets,
        private QueueVisibilityService $visibility,
        private MedicalDutyService $medicalDuty,
    ) {}

    public function call(
        QueueEntry $entry,
        int $expectedVersion,
        string $servicePointPublicId,
        User $user,
        HealthUnit $unit,
        Request $request,
        bool $recall = false,
    ): QueueEntry {
        return DB::transaction(function () use ($entry, $expectedVersion, $servicePointPublicId, $user, $unit, $request, $recall): QueueEntry {
            $locked = $this->lockEntry($entry, $unit, $user, $expectedVersion);
            $fromStatus = $locked->statusEnum();
            $allowed = $recall
                ? [QueueEntryStatus::Called]
                : [QueueEntryStatus::Waiting, QueueEntryStatus::Absent];
            if (! in_array($fromStatus, $allowed, true)) {
                $this->invalidStatus($locked, $recall ? 'rechamar' : 'chamar');
            }

            $servicePoint = $this->resolveServicePoint($locked->queue, $servicePointPublicId);
            $now = now();
            $callNumber = (int) $locked->call_count + 1;
            $locked->update([
                'status' => QueueEntryStatus::Called,
                'service_point_id' => $servicePoint->getKey(),
                'first_called_at' => $locked->first_called_at ?? $now,
                'last_called_at' => $now,
                'exited_at' => null,
                'exit_reason' => null,
                'call_count' => $callNumber,
                'lock_version' => $locked->version() + 1,
            ]);

            $call = $locked->calls()->create([
                'queue_id' => $locked->queue_id,
                'service_point_id' => $servicePoint->getKey(),
                'called_by' => $user->getKey(),
                'call_type' => $recall ? QueueCallType::Recall : QueueCallType::Call,
                'call_number' => $callNumber,
                'ticket_snapshot' => $locked->ticket_number,
                'called_at' => $now,
            ]);
            $calledStatus = (string) $locked->queue->department->type === 'triage'
                ? EncounterStatus::CalledToTriage
                : EncounterStatus::CalledToMedical;
            $this->transitionEncounter($locked, $calledStatus, $user, $recall ? 'Senha rechamada' : 'Senha chamada');
            $this->history(
                $locked,
                $fromStatus,
                QueueEntryStatus::Called,
                $recall ? 'recalled' : 'called',
                $user,
                $servicePoint,
            );
            $this->audit->execute(
                $recall ? 'queue.entry_recalled' : 'queue.entry_called',
                $request,
                $user,
                ['entry' => $locked->public_id, 'call' => $call->public_id, 'ticket' => $locked->ticket_number],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $this->reload($locked);
        });
    }

    public function startService(
        QueueEntry $entry,
        int $expectedVersion,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): QueueEntry {
        return DB::transaction(function () use ($entry, $expectedVersion, $user, $unit, $request): QueueEntry {
            $locked = $this->lockEntry($entry, $unit, $user, $expectedVersion);
            if ($locked->statusEnum() !== QueueEntryStatus::Called) {
                $this->invalidStatus($locked, 'iniciar o atendimento');
            }
            if ($locked->service_point_id === null) {
                throw ValidationException::withMessages(['service_point' => 'Chame o paciente para um ponto de atendimento antes de iniciar.']);
            }

            $now = now();
            $locked->update([
                'status' => QueueEntryStatus::InService,
                'assigned_user_id' => $user->getKey(),
                'service_started_at' => $now,
                'lock_version' => $locked->version() + 1,
            ]);

            $departmentType = (string) $locked->queue->department->type;
            $this->transitionEncounter(
                $locked,
                $departmentType === 'triage' ? EncounterStatus::InTriage : EncounterStatus::InMedicalCare,
                $user,
                'Atendimento iniciado a partir da fila',
                $departmentType === 'triage'
                    ? ['triage_started_at' => $now, 'current_room_id' => $locked->servicePoint?->room_id]
                    : ['medical_started_at' => $now, 'current_room_id' => $locked->servicePoint?->room_id],
            );

            $this->history($locked, QueueEntryStatus::Called, QueueEntryStatus::InService, 'service_started', $user, $locked->servicePoint);
            $this->audit->execute(
                'queue.service_started',
                $request,
                $user,
                ['entry' => $locked->public_id, 'ticket' => $locked->ticket_number],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $this->reload($locked);
        });
    }

    public function markAbsent(
        QueueEntry $entry,
        int $expectedVersion,
        string $reason,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): QueueEntry {
        return DB::transaction(function () use ($entry, $expectedVersion, $reason, $user, $unit, $request): QueueEntry {
            $locked = $this->lockEntry($entry, $unit, $user, $expectedVersion);
            if ($locked->statusEnum() !== QueueEntryStatus::Called) {
                $this->invalidStatus($locked, 'marcar ausência');
            }
            if ((int) $locked->call_count < (int) $locked->queue->minimum_calls_before_absent) {
                throw ValidationException::withMessages([
                    'confirmation' => 'A fila exige mais chamadas antes de registrar ausência.',
                ]);
            }

            $locked->update([
                'status' => QueueEntryStatus::Absent,
                'exited_at' => now(),
                'exit_reason' => $reason,
                'lock_version' => $locked->version() + 1,
            ]);
            $this->history($locked, QueueEntryStatus::Called, QueueEntryStatus::Absent, 'marked_absent', $user, $locked->servicePoint, $reason);
            $this->audit->execute(
                'queue.entry_absent',
                $request,
                $user,
                ['entry' => $locked->public_id, 'reason' => $reason],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $this->reload($locked);
        });
    }

    public function returnToQueue(
        QueueEntry $entry,
        int $expectedVersion,
        string $reason,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): QueueEntry {
        return DB::transaction(function () use ($entry, $expectedVersion, $reason, $user, $unit, $request): QueueEntry {
            $locked = $this->lockEntry($entry, $unit, $user, $expectedVersion);
            if ($locked->statusEnum() !== QueueEntryStatus::Absent) {
                $this->invalidStatus($locked, 'devolver à fila');
            }

            $locked->update([
                'status' => QueueEntryStatus::Waiting,
                'service_point_id' => null,
                'assigned_user_id' => null,
                'exited_at' => null,
                'exit_reason' => null,
                'lock_version' => $locked->version() + 1,
            ]);
            $waitingStatus = (string) $locked->queue->department->type === 'triage'
                ? EncounterStatus::WaitingTriage
                : EncounterStatus::WaitingMedical;
            $this->transitionEncounter($locked, $waitingStatus, $user, 'Paciente devolvido à fila');
            $this->history($locked, QueueEntryStatus::Absent, QueueEntryStatus::Waiting, 'returned', $user, null, $reason);
            $this->audit->execute(
                'queue.entry_returned',
                $request,
                $user,
                ['entry' => $locked->public_id, 'reason' => $reason],
                (int) $unit->getKey(),
                (int) $locked->encounter->patient_id,
                (int) $locked->encounter_id,
            );

            return $this->reload($locked);
        });
    }

    public function transfer(
        QueueEntry $entry,
        int $expectedVersion,
        string $destinationQueuePublicId,
        string $reason,
        bool $preservePriority,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): QueueEntry {
        return DB::transaction(function () use ($entry, $expectedVersion, $destinationQueuePublicId, $reason, $preservePriority, $user, $unit, $request): QueueEntry {
            $source = $this->lockEntry($entry, $unit, $user, $expectedVersion);
            if (! in_array($source->statusEnum(), [QueueEntryStatus::Waiting, QueueEntryStatus::Called, QueueEntryStatus::Absent], true)) {
                $this->invalidStatus($source, 'transferir');
            }

            $destinationQueue = Queue::query()
                ->where('public_id', $destinationQueuePublicId)
                ->where('health_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            if ($destinationQueue->is($source->queue)) {
                throw ValidationException::withMessages(['destination_queue' => 'Selecione uma fila de destino diferente.']);
            }

            $fromStatus = $source->statusEnum();
            $source->update([
                'status' => QueueEntryStatus::Transferred,
                'exited_at' => now(),
                'exit_reason' => $reason,
                'lock_version' => $source->version() + 1,
            ]);
            $this->history($source, $fromStatus, QueueEntryStatus::Transferred, 'transferred', $user, $source->servicePoint, $reason, [
                'destination_queue' => $destinationQueue->public_id,
            ]);

            $destination = QueueEntry::query()->create([
                'encounter_id' => $source->encounter_id,
                'queue_id' => $destinationQueue->getKey(),
                'ticket_number' => $this->tickets->next($destinationQueue),
                'priority_weight' => $preservePriority ? $source->priority_weight : 0,
                'status' => QueueEntryStatus::Waiting,
                'entered_at' => now(),
            ]);
            $this->history($destination, null, QueueEntryStatus::Waiting, 'entered_by_transfer', $user, null, $reason, [
                'source_entry' => $source->public_id,
            ]);

            QueueTransfer::query()->create([
                'encounter_id' => $source->encounter_id,
                'source_entry_id' => $source->getKey(),
                'destination_entry_id' => $destination->getKey(),
                'source_queue_id' => $source->queue_id,
                'destination_queue_id' => $destinationQueue->getKey(),
                'transferred_by' => $user->getKey(),
                'reason' => $reason,
                'priority_preserved' => $preservePriority,
                'transferred_at' => now(),
            ]);

            $destinationType = (string) $destinationQueue->department->type;
            $this->transitionEncounter(
                $source,
                $destinationType === 'triage' ? EncounterStatus::WaitingTriage : EncounterStatus::WaitingMedical,
                $user,
                'Atendimento transferido entre filas',
                [
                    'current_department_id' => $destinationQueue->department_id,
                    'assigned_specialty_id' => $destinationQueue->specialty_id,
                    'current_room_id' => null,
                ],
            );

            $this->audit->execute(
                'queue.entry_transferred',
                $request,
                $user,
                [
                    'source_entry' => $source->public_id,
                    'destination_entry' => $destination->public_id,
                    'destination_queue' => $destinationQueue->public_id,
                ],
                (int) $unit->getKey(),
                (int) $source->encounter->patient_id,
                (int) $source->encounter_id,
            );

            return $this->reload($destination);
        });
    }

    private function lockEntry(QueueEntry $entry, HealthUnit $unit, User $user, int $expectedVersion): QueueEntry
    {
        $locked = QueueEntry::query()
            ->with(['queue.department', 'servicePoint', 'encounter'])
            ->whereKey($entry->getKey())
            ->whereHas('queue', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->version() !== $expectedVersion) {
            throw ValidationException::withMessages([
                'version' => 'A fila foi atualizada por outro profissional. Atualize a tela e tente novamente.',
            ]);
        }
        $this->visibility->ensureCanAccess($locked->queue, $user);
        if ((string) $locked->queue->department->type === 'medical' && $user->hasRole('doctor')) {
            $this->medicalDuty->ensureCheckedIn($user, $unit);
        }

        return $locked;
    }

    private function resolveServicePoint(Queue $queue, string $publicId): ServicePoint
    {
        $point = $queue->servicePoints()
            ->where('service_points.public_id', $publicId)
            ->where('service_points.is_active', true)
            ->first();
        if (! $point instanceof ServicePoint) {
            throw ValidationException::withMessages(['service_point' => 'O ponto de atendimento não está autorizado para esta fila.']);
        }

        return $point;
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function history(
        QueueEntry $entry,
        ?QueueEntryStatus $from,
        QueueEntryStatus $to,
        string $action,
        User $user,
        ?ServicePoint $point,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $entry->history()->create([
            'from_status' => $from,
            'to_status' => $to,
            'action' => $action,
            'service_point_id' => $point?->getKey(),
            'performed_by' => $user->getKey(),
            'reason' => $reason,
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }

    private function invalidStatus(QueueEntry $entry, string $action): never
    {
        throw ValidationException::withMessages([
            'status' => "Não foi possível {$action} a senha {$entry->ticket_number}. A situação atual é {$entry->statusEnum()->label()}.",
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function transitionEncounter(
        QueueEntry $entry,
        EncounterStatus $to,
        User $user,
        string $reason,
        array $attributes = [],
    ): void {
        $encounter = $entry->encounter;
        $from = $encounter->currentStatusEnum();
        if ($from === $to && $attributes === []) {
            return;
        }

        $encounter->update([
            ...$attributes,
            'current_status' => $to,
            'lock_version' => (int) $encounter->lock_version + 1,
        ]);
        if ($from !== $to) {
            $encounter->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'metadata' => ['queue_entry' => $entry->public_id],
                'changed_by' => $user->getKey(),
                'changed_at' => now(),
            ]);
        }
    }

    private function reload(QueueEntry $entry): QueueEntry
    {
        return $entry->fresh([
            'queue.department', 'servicePoint', 'assignedUser',
            'encounter.patient', 'encounter.arrivalMethod',
        ]) ?? $entry;
    }
}
