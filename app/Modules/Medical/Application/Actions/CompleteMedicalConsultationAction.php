<?php

declare(strict_types=1);

namespace App\Modules\Medical\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Medical\Application\Services\MedicalConsultationGuard;
use App\Modules\Medical\Domain\Enums\DestinationType;
use App\Modules\Medical\Domain\Enums\MedicalConsultationStatus;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
use App\Modules\Medical\Infrastructure\Eloquent\MedicalConsultation;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CompleteMedicalConsultationAction
{
    public function __construct(
        private MedicalConsultationGuard $guard,
        private RecordAuditEventAction $audit,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(
        MedicalConsultation $consultation,
        array $data,
        User $user,
        HealthUnit $unit,
        Request $request,
    ): EncounterDestination {
        return DB::transaction(function () use ($consultation, $data, $user, $unit, $request): EncounterDestination {
            $locked = $this->guard->lockDraft($consultation, $user, $unit, (int) $data['version']);
            $locked->load(['physicalExam', 'diagnoses']);
            $this->ensureClinicalRequirements($locked);
            $sourceEntry = QueueEntry::query()->whereKey($locked->queue_entry_id)->lockForUpdate()->firstOrFail();
            if ($sourceEntry->statusEnum() !== QueueEntryStatus::InService || $sourceEntry->assigned_user_id !== $user->getKey()) {
                throw ValidationException::withMessages(['queue_entry' => 'A entrada da fila não está mais atribuída a este médico.']);
            }

            $destinationType = DestinationType::from((string) $data['destination_type']);
            $now = now();
            $hashPayload = [
                'consultation' => Arr::only($locked->getAttributes(), [
                    'chief_complaint', 'present_illness_history', 'personal_history', 'family_history',
                    'surgical_history', 'current_medications', 'allergies_summary', 'habits',
                    'gynecological_history', 'review_of_systems', 'additional_notes', 'conduct_summary',
                    'procedures_summary', 'guidance', 'physical_exam_justification',
                ]),
                'physical_exam' => $locked->physicalExam?->getAttributes(),
                'diagnoses' => $locked->diagnoses->map->getAttributes()->all(),
            ];
            $locked->update([
                'status' => MedicalConsultationStatus::Finalized,
                'content_hash' => hash('sha256', (string) json_encode($hashPayload, JSON_UNESCAPED_UNICODE)),
                'finalized_at' => $now,
                'finalized_by' => $user->getKey(),
                'lock_version' => $locked->version() + 1,
            ]);

            $destination = EncounterDestination::query()->create([
                'encounter_id' => $locked->encounter_id,
                'medical_consultation_id' => $locked->getKey(),
                'destination_type' => $destinationType,
                ...Arr::only($data, [
                    'reason', 'clinical_condition', 'clinical_summary', 'instructions', 'warning_signs',
                    'follow_up', 'destination_institution', 'destination_city', 'destination_department',
                    'bed_type', 'transport_method', 'last_known_location', 'contact_attempts', 'death_cause',
                ]),
                'details' => $data['details'] ?? null,
                'recorded_by' => $user->getKey(),
                'occurred_at' => $data['occurred_at'] ?? $now,
            ]);

            $sourceEntry->update([
                'status' => QueueEntryStatus::Completed,
                'exited_at' => $now,
                'exit_reason' => $destinationType->label(),
                'lock_version' => $sourceEntry->version() + 1,
            ]);
            $sourceEntry->history()->create([
                'from_status' => QueueEntryStatus::InService,
                'to_status' => QueueEntryStatus::Completed,
                'action' => 'medical_care_completed',
                'service_point_id' => $sourceEntry->service_point_id,
                'performed_by' => $user->getKey(),
                'metadata' => ['destination_type' => $destinationType->value],
                'occurred_at' => $now,
            ]);

            $encounter = $locked->encounter;
            $fromStatus = $encounter->currentStatusEnum();
            $toStatus = $destinationType->encounterStatus();
            $encounter->update([
                'current_status' => $toStatus,
                'medical_finished_at' => $now,
                'closed_at' => $toStatus->isFinal() ? $now : null,
                'current_room_id' => null,
                'lock_version' => (int) $encounter->lock_version + 1,
            ]);
            $encounter->statusHistory()->create([
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'reason' => 'Atendimento médico finalizado: '.$destinationType->label(),
                'metadata' => ['consultation' => $locked->public_id, 'destination' => $destination->public_id],
                'changed_by' => $user->getKey(),
                'changed_at' => $now,
            ]);

            $this->audit->execute(
                'medical.consultation_completed',
                $request,
                $user,
                ['consultation' => $locked->public_id, 'destination' => $destination->public_id, 'destination_type' => $destinationType->value],
                (int) $unit->getKey(),
                (int) $encounter->patient_id,
                (int) $encounter->getKey(),
            );

            return $destination;
        });
    }

    private function ensureClinicalRequirements(MedicalConsultation $consultation): void
    {
        $errors = [];
        if (blank($consultation->chief_complaint) && blank($consultation->present_illness_history)) {
            $errors['chief_complaint'] = 'Registre a queixa principal ou a história da doença atual.';
        }
        if (! $consultation->physicalExam && blank($consultation->physical_exam_justification)) {
            $errors['physical_exam'] = 'Registre o exame físico ou justifique por que não foi realizado.';
        }
        if (blank($consultation->conduct_summary)) {
            $errors['conduct_summary'] = 'Registre a conduta antes de finalizar.';
        }
        if ($consultation->diagnoses->where('status', 'active')->isEmpty()) {
            $errors['diagnosis'] = 'Registre ao menos um diagnóstico ou hipótese.';
        }
        if ($consultation->diagnoses->where('status', 'active')->where('is_primary', true)->count() !== 1) {
            $errors['primary_diagnosis'] = 'Defina exatamente um diagnóstico principal ativo.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
