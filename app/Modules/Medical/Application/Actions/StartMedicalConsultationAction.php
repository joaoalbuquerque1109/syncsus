<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Queues\Application\Services\QueueVisibilityService;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class StartMedicalConsultationAction
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
    ): MedicalConsultation {
        return DB::transaction(function () use ($entry, $expectedVersion, $user, $unit, $request): MedicalConsultation {
            $locked = QueueEntry::query()
                ->with(['queue.department', 'servicePoint.room', 'encounter.medicalConsultation'])
                ->whereKey($entry->getKey())
                ->whereHas('queue', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->lockForUpdate()
                ->firstOrFail();
            if ((string) $locked->queue->department->type !== 'medical') {
                throw ValidationException::withMessages(['entry' => 'A entrada selecionada não pertence a uma fila médica.']);
            }
            $this->visibility->ensureCanAccess($locked->queue, $user);
            $this->visibility->ensureCanAccessEntry($locked, $user);
            if ($locked->version() !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'A fila foi atualizada. Recarregue antes de iniciar o atendimento.']);
            }

            $existing = $locked->encounter->medicalConsultation;
            if ($existing instanceof MedicalConsultation) {
                if ($existing->professional_id !== $user->getKey()) {
                    throw ValidationException::withMessages(['entry' => 'Este atendimento já foi iniciado por outro médico.']);
                }

                return $existing;
            }
            if ($locked->statusEnum() !== QueueEntryStatus::Called) {
                throw ValidationException::withMessages(['status' => 'A senha precisa estar chamada antes do início do atendimento médico.']);
            }
            if ($locked->service_point_id === null) {
                throw ValidationException::withMessages(['service_point' => 'Chame o paciente para um consultório antes de iniciar.']);
            }

            $now = now();
            $locked->update([
                'status' => QueueEntryStatus::InService,
                'assigned_user_id' => $user->getKey(),
                'service_started_at' => $now,
                'lock_version' => $locked->version() + 1,
            ]);
            $locked->history()->create([
                'from_status' => QueueEntryStatus::Called,
                'to_status' => QueueEntryStatus::InService,
                'action' => 'medical_care_started',
                'service_point_id' => $locked->service_point_id,
                'performed_by' => $user->getKey(),
                'occurred_at' => $now,
            ]);

            $encounter = $locked->encounter;
            $fromStatus = $encounter->currentStatusEnum();
            $encounter->update([
                'current_status' => EncounterStatus::InMedicalCare,
                'medical_started_at' => $encounter->medical_started_at ?? $now,
                'current_room_id' => $locked->servicePoint->room_id,
                'lock_version' => (int) $encounter->lock_version + 1,
            ]);
            if ($fromStatus !== EncounterStatus::InMedicalCare) {
                $encounter->statusHistory()->create([
                    'from_status' => $fromStatus,
                    'to_status' => EncounterStatus::InMedicalCare,
                    'reason' => 'Atendimento médico iniciado',
                    'metadata' => ['queue_entry' => $locked->public_id],
                    'changed_by' => $user->getKey(),
                    'changed_at' => $now,
                ]);
            }

            $consultation = MedicalConsultation::query()->create([
                'encounter_id' => $encounter->getKey(),
                'queue_entry_id' => $locked->getKey(),
                'professional_id' => $user->getKey(),
                'specialty_id' => $locked->queue->specialty_id,
                'room_id' => $locked->servicePoint->room_id,
                'status' => MedicalConsultationStatus::Draft,
                'started_at' => $now,
            ]);
            $this->audit->execute(
                'medical.consultation_started',
                $request,
                $user,
                ['consultation' => $consultation->public_id, 'ticket' => $locked->ticket_number],
                (int) $unit->getKey(),
                (int) $encounter->patient_id,
                (int) $encounter->getKey(),
            );

            return $consultation;
        });
    }
}
