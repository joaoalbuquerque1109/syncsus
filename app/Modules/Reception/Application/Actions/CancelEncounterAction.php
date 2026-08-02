<?php

declare(strict_types=1);

namespace App\Modules\Reception\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CancelEncounterAction
{
    public function __construct(private RecordAuditEventAction $audit) {}

    public function execute(
        Encounter $encounter,
        int $expectedVersion,
        string $reason,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): Encounter {
        return DB::transaction(function () use ($encounter, $expectedVersion, $reason, $user, $unit, $request): Encounter {
            $locked = Encounter::query()
                ->with(['patient', 'queueEntries', 'triageAssessment', 'medicalConsultation'])
                ->whereKey($encounter->getKey())
                ->where('health_unit_id', $unit->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) $locked->lock_version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => 'O atendimento foi atualizado. Recarregue a pagina antes de cancelar.',
                ]);
            }
            $from = $locked->currentStatusEnum();
            if ($from->isFinal()) {
                throw ValidationException::withMessages([
                    'reason' => 'Atendimentos finalizados nao podem ser cancelados.',
                ]);
            }
            $this->authorizeCancellation($locked, $from, $user);

            $now = now();
            $triage = $locked->triageAssessment;
            if ($triage instanceof TriageAssessment && $triage->statusEnum() === TriageAssessmentStatus::Draft) {
                $triage->update([
                    'status' => TriageAssessmentStatus::Cancelled,
                    'cancelled_at' => $now,
                    'cancelled_by' => $user->getKey(),
                    'cancellation_reason' => $reason,
                    'lock_version' => $triage->version() + 1,
                ]);
            }
            $consultation = $locked->medicalConsultation;
            if ($consultation instanceof MedicalConsultation
                && $consultation->statusEnum() === MedicalConsultationStatus::Draft) {
                $consultation->update([
                    'status' => MedicalConsultationStatus::Cancelled,
                    'cancelled_at' => $now,
                    'cancelled_by' => $user->getKey(),
                    'cancellation_reason' => $reason,
                    'lock_version' => $consultation->version() + 1,
                ]);
            }
            foreach ($locked->queueEntries as $entry) {
                if (! in_array($entry->statusEnum(), [
                    QueueEntryStatus::Waiting,
                    QueueEntryStatus::Called,
                    QueueEntryStatus::InService,
                    QueueEntryStatus::Absent,
                ], true)) {
                    continue;
                }
                $entryFrom = $entry->statusEnum();
                $entry->update([
                    'status' => QueueEntryStatus::Cancelled,
                    'exited_at' => $now,
                    'exit_reason' => $reason,
                    'lock_version' => $entry->version() + 1,
                ]);
                $entry->history()->create([
                    'from_status' => $entryFrom,
                    'to_status' => QueueEntryStatus::Cancelled,
                    'action' => 'encounter_cancelled',
                    'performed_by' => $user->getKey(),
                    'reason' => $reason,
                    'occurred_at' => $now,
                ]);
            }

            $locked->update([
                'current_status' => EncounterStatus::Cancelled,
                'cancellation_reason' => $reason,
                'closed_at' => $now,
                'closed_by' => $user->getKey(),
                'current_department_id' => null,
                'current_room_id' => null,
                'lock_version' => $expectedVersion + 1,
            ]);
            $locked->statusHistory()->create([
                'from_status' => $from,
                'to_status' => EncounterStatus::Cancelled,
                'reason' => $reason,
                'metadata' => ['source' => 'reception'],
                'changed_by' => $user->getKey(),
                'changed_at' => $now,
            ]);
            $this->audit->execute(
                'encounter.cancelled',
                $request,
                $user,
                ['from_status' => $from->value, 'reason' => $reason],
                (int) $unit->getKey(),
                (int) $locked->patient_id,
                (int) $locked->getKey(),
            );

            return $locked->fresh(['patient', 'queueEntries.queue']) ?? $locked;
        });
    }

    private function authorizeCancellation(Encounter $encounter, EncounterStatus $status, User $user): void
    {
        if ($user->isPlatformAdministrator()) {
            return;
        }

        $administrativeStatuses = [
            EncounterStatus::Opened,
            EncounterStatus::WaitingTriage,
            EncounterStatus::CalledToTriage,
            EncounterStatus::WaitingMedical,
            EncounterStatus::CalledToMedical,
        ];
        if (in_array($status, $administrativeStatuses, true)) {
            abort_unless($user->can('encounters.cancel'), 403);

            return;
        }

        abort_unless($user->can('encounters.cancel_clinical'), 403);
        if ($status === EncounterStatus::InTriage) {
            abort_unless($encounter->triageAssessment?->professional_id === $user->getKey(), 403);

            return;
        }

        abort_unless($encounter->medicalConsultation?->professional_id === $user->getKey(), 403);
    }
}
