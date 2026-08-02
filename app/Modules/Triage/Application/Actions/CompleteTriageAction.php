<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\RiskLevel;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\PatientAllergy;
use App\Modules\Queues\Application\Services\QueueTicketService;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Triage\Domain\Enums\TriageAssessmentStatus;
use App\Modules\Triage\Infrastructure\Eloquent\TriageAssessment;
use App\Modules\Triage\Infrastructure\Eloquent\TriageDiscriminator;
use App\Modules\Triage\Infrastructure\Eloquent\TriageFlowchart;
use App\Modules\Triage\Infrastructure\Eloquent\TriageProtocol;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CompleteTriageAction
{
    public function __construct(
        private QueueTicketService $tickets,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(TriageAssessment $assessment, array $data, User $user, HealthUnit $unit, Request $request): QueueEntry
    {
        return DB::transaction(function () use ($assessment, $data, $user, $unit, $request): QueueEntry {
            $locked = TriageAssessment::query()
                ->with(['encounter.patient', 'queueEntry.queue'])
                ->whereKey($assessment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $this->ensureCompletable($locked, $user, (int) $data['version']);

            $protocol = TriageProtocol::query()->whereKey($data['triage_protocol_id'])->where('is_active', true)->firstOrFail();
            $flowchart = TriageFlowchart::query()
                ->whereKey($data['triage_flowchart_id'])
                ->where('triage_protocol_id', $protocol->getKey())
                ->where('is_active', true)
                ->firstOrFail();
            $discriminator = TriageDiscriminator::query()
                ->whereKey($data['triage_discriminator_id'])
                ->where('triage_flowchart_id', $flowchart->getKey())
                ->where('is_active', true)
                ->firstOrFail();
            $risk = RiskLevel::query()->whereKey($data['risk_level_id'])->where('is_active', true)->firstOrFail();
            $destinationQueue = Queue::query()
                ->with('department')
                ->whereKey($data['destination_queue_id'])
                ->where('health_unit_id', $unit->getKey())
                ->whereHas('department', fn ($query) => $query->where('type', 'medical'))
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            if ($destinationQueue->is($locked->queueEntry->queue)) {
                throw ValidationException::withMessages(['destination_queue_id' => 'Selecione uma fila de destino diferente da triagem atual.']);
            }

            $sourceEntry = QueueEntry::query()->whereKey($locked->queue_entry_id)->lockForUpdate()->firstOrFail();
            if ($sourceEntry->statusEnum() !== QueueEntryStatus::InService
                || ($sourceEntry->assigned_user_id !== $user->getKey() && ! $user->isPlatformAdministrator())) {
                throw ValidationException::withMessages(['queue_entry' => 'A entrada da fila não está mais atribuída a este profissional.']);
            }

            $now = now();
            $locked->update([
                'status' => TriageAssessmentStatus::Finalized,
                'triage_protocol_id' => $protocol->getKey(),
                'protocol_version' => $protocol->version,
                'triage_flowchart_id' => $flowchart->getKey(),
                'triage_discriminator_id' => $discriminator->getKey(),
                'risk_level_id' => $risk->getKey(),
                'risk_justification' => $data['risk_justification'],
                'destination_queue_id' => $destinationQueue->getKey(),
                'routing_notes' => $data['routing_notes'] ?? null,
                'finalized_at' => $now,
                'finalized_by' => $user->getKey(),
                'lock_version' => $locked->version() + 1,
            ]);

            $sourceEntry->update([
                'status' => QueueEntryStatus::Completed,
                'exited_at' => $now,
                'exit_reason' => 'Triagem finalizada',
                'lock_version' => $sourceEntry->version() + 1,
            ]);
            $sourceEntry->history()->create([
                'from_status' => QueueEntryStatus::InService,
                'to_status' => QueueEntryStatus::Completed,
                'action' => 'triage_completed',
                'service_point_id' => $sourceEntry->service_point_id,
                'performed_by' => $user->getKey(),
                'occurred_at' => $now,
            ]);

            $priority = (int) $risk->priority_weight;
            if ($locked->encounter->administrativePriorityEnum() !== AdministrativePriority::None) {
                $priority += 10;
            }
            $destinationEntry = QueueEntry::query()->create([
                'encounter_id' => $locked->encounter_id,
                'queue_id' => $destinationQueue->getKey(),
                'ticket_number' => $this->tickets->next($destinationQueue),
                'priority_weight' => $priority,
                'status' => QueueEntryStatus::Waiting,
                'entered_at' => $now,
            ]);
            $destinationEntry->history()->create([
                'from_status' => null,
                'to_status' => QueueEntryStatus::Waiting,
                'action' => 'entered_after_triage',
                'performed_by' => $user->getKey(),
                'metadata' => ['triage' => $locked->public_id, 'risk_code' => $risk->code],
                'occurred_at' => $now,
            ]);

            $encounter = $locked->encounter;
            $fromStatus = $encounter->currentStatusEnum();
            $encounter->update([
                'current_status' => EncounterStatus::WaitingMedical,
                'risk_level_id' => $risk->getKey(),
                'triage_finished_at' => $now,
                'current_department_id' => $destinationQueue->department_id,
                'assigned_specialty_id' => $destinationQueue->specialty_id,
                'current_room_id' => null,
                'lock_version' => (int) $encounter->lock_version + 1,
            ]);
            $encounter->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => EncounterStatus::WaitingMedical,
                'reason' => 'Triagem finalizada e encaminhada',
                'metadata' => ['triage' => $locked->public_id, 'destination_queue' => $destinationQueue->public_id],
                'changed_by' => $user->getKey(),
                'changed_at' => $now,
            ]);

            if ($locked->has_reported_allergies && filled($locked->reported_allergies)) {
                PatientAllergy::query()->firstOrCreate(
                    [
                        'patient_id' => $encounter->patient_id,
                        'substance' => 'Relato registrado na triagem',
                        'reaction' => $locked->reported_allergies,
                        'status' => 'active',
                    ],
                    [
                        'severity' => null,
                        'source' => 'triage',
                        'recorded_by' => $user->getKey(),
                        'recorded_at' => $now,
                    ],
                );
            }

            $this->audit->execute(
                'triage.completed',
                $request,
                $user,
                ['triage' => $locked->public_id, 'risk_code' => $risk->code, 'destination_queue' => $destinationQueue->public_id],
                (int) $unit->getKey(),
                (int) $encounter->patient_id,
                (int) $encounter->getKey(),
            );

            return $destinationEntry;
        });
    }

    private function ensureCompletable(TriageAssessment $assessment, User $user, int $version): void
    {
        if ($assessment->statusEnum() !== TriageAssessmentStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Esta triagem já foi finalizada.']);
        }
        if (! $user->canManageClinicalRecordOwnedBy($assessment->professional_id)) {
            throw ValidationException::withMessages(['professional' => 'Somente o profissional responsável ou o administrador pode finalizar a triagem.']);
        }
        if ($assessment->version() !== $version) {
            throw ValidationException::withMessages(['version' => 'A triagem foi atualizada. Recarregue a página antes de finalizar.']);
        }
        $errors = [];
        if (blank($assessment->chief_complaint)) {
            $errors['chief_complaint'] = 'Registre a queixa principal antes de finalizar.';
        }
        if (blank($assessment->brief_history)) {
            $errors['brief_history'] = 'Registre a história resumida antes de finalizar.';
        }
        if ($assessment->has_reported_allergies === null) {
            $errors['has_reported_allergies'] = 'Informe se há alergias relatadas.';
        }
        if ($assessment->has_reported_allergies && blank($assessment->reported_allergies)) {
            $errors['reported_allergies'] = 'Descreva as alergias relatadas.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
