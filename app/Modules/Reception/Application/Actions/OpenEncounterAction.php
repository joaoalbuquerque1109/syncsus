<?php

declare(strict_types=1);

namespace App\Modules\Reception\Application\Actions;

use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Application\Actions\RecordAuditEventAction;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Laboratory\Application\Actions\CreateReceptionExamOrderAction;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Application\Services\QueueTicketService;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Modules\Reception\Application\Services\NumberSequenceService;
use App\Modules\Reception\Domain\Enums\AdministrativePriority;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use App\Modules\Reception\Infrastructure\Eloquent\IdempotencyKey;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class OpenEncounterAction
{
    public function __construct(
        private NumberSequenceService $sequences,
        private QueueTicketService $tickets,
        private RecordAuditEventAction $audit,
        private CreateReceptionExamOrderAction $createExamOrder,
    ) {}

    /** @param array<string, mixed> $data */
    public function execute(array $data, User $user, HealthUnit $unit, Request $request): Encounter
    {
        $hashPayload = Arr::except($data, ['idempotency_key']);
        ksort($hashPayload);
        $requestHash = hash('sha256', json_encode($hashPayload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($data, $user, $unit, $request, $requestHash): Encounter {
            $existingKey = IdempotencyKey::query()
                ->where('user_id', $user->getKey())
                ->where('route_name', 'reception.store')
                ->where('idempotency_key', $data['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existingKey !== null) {
                if (! hash_equals((string) $existingKey->request_hash, $requestHash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Este formulário já foi usado com dados diferentes. Recarregue a página.',
                    ]);
                }

                $encounterPublicId = $existingKey->encounterPublicId();
                if ($encounterPublicId === null) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => 'Não foi possível recuperar o resultado anterior. Recarregue a página.',
                    ]);
                }

                return Encounter::query()->where('public_id', $encounterPublicId)->firstOrFail();
            }

            $patient = Patient::query()->where('public_id', $data['patient_public_id'])->lockForUpdate()->firstOrFail();
            abort_unless((int) $patient->organization_id === (int) $unit->organization_id, 404);
            $hasActiveEncounter = Encounter::query()
                ->where('patient_id', $patient->getKey())
                ->where('health_unit_id', $unit->getKey())
                ->whereNull('closed_at')
                ->exists();
            if ($hasActiveEncounter) {
                throw ValidationException::withMessages([
                    'patient_public_id' => 'Este paciente já possui atendimento ativo nesta unidade.',
                ]);
            }

            $entryType = EntryType::query()
                ->where('organization_id', $unit->organization_id)
                ->whereKey($data['entry_type_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            if ($patient->is_provisional && ! $entryType->allows_provisional_patient) {
                throw ValidationException::withMessages([
                    'patient_public_id' => 'Este tipo de entrada exige identificacao definitiva.',
                ]);
            }

            $queueId = (int) ($data['queue_id'] ?? 0);
            if ($queueId === 0) {
                $queueId = (int) $entryType->default_queue_id;
            }
            $queue = Queue::query()
                ->with('department')
                ->whereKey($queueId)
                ->where('health_unit_id', $unit->getKey())
                ->where('is_active', true)
                ->first();
            if ($queue === null) {
                throw ValidationException::withMessages([
                    'queue_id' => 'A fila escolhida não pertence ao destino selecionado.',
                ]);
            }
            $expectedDepartmentType = $entryType->requires_triage ? 'triage' : 'medical';
            if ((string) $queue->department->type !== $expectedDepartmentType) {
                throw ValidationException::withMessages([
                    'queue_id' => $entryType->requires_triage
                        ? 'A entrada deve iniciar na triagem.'
                        : 'A entrada dispensa triagem e deve iniciar na fila medica.',
                ]);
            }
            if (isset($data['department_id']) && (int) $data['department_id'] !== (int) $queue->department_id) {
                throw ValidationException::withMessages([
                    'department_id' => 'O setor nao corresponde a fila selecionada.',
                ]);
            }

            $now = now();
            $initialStatus = $entryType->requires_triage
                ? EncounterStatus::WaitingTriage
                : EncounterStatus::WaitingMedical;
            $sequence = $this->sequences->next('encounter:'.$unit->getKey(), $now->format('Ymd'));
            $encounter = Encounter::query()->create([
                'encounter_number' => sprintf('%s-%s-%04d', $unit->code, $now->format('Ymd'), $sequence),
                'patient_id' => $patient->getKey(),
                'health_unit_id' => $unit->getKey(),
                'entry_type_id' => $data['entry_type_id'],
                'arrival_method_id' => $data['arrival_method_id'],
                'current_status' => $initialStatus,
                'administrative_priority' => $data['administrative_priority'],
                'arrival_at' => $data['arrival_at'] ?? $now,
                'registration_at' => $now,
                'assigned_specialty_id' => $queue->specialty_id ?? ($data['specialty_id'] ?? null),
                'current_department_id' => $queue->department_id,
                'created_by' => $user->getKey(),
            ]);

            $encounter->receptionRecord()->create([
                'operator_id' => $user->getKey(),
                ...Arr::only($data, ['origin', 'entry_reason', 'reception_notes', 'vehicle_information']),
            ]);

            if (filled($data['companion_name'] ?? null)) {
                $encounter->companions()->create([
                    'full_name' => $data['companion_name'],
                    'cpf' => $data['companion_cpf'] ?? null,
                    'phone' => $data['companion_phone'] ?? null,
                    'relationship' => $data['companion_relationship'] ?? null,
                    'is_legal_guardian' => (bool) ($data['companion_is_guardian'] ?? false),
                    'authorized_at' => $now,
                ]);
            }

            $encounter->statusHistory()->create([
                'from_status' => null,
                'to_status' => EncounterStatus::Opened,
                'reason' => 'Atendimento aberto pela recepção',
                'changed_by' => $user->getKey(),
                'changed_at' => $now,
            ]);
            $encounter->statusHistory()->create([
                'from_status' => EncounterStatus::Opened,
                'to_status' => $initialStatus,
                'reason' => 'Encaminhado à fila inicial',
                'changed_by' => $user->getKey(),
                'changed_at' => $now,
            ]);

            $priority = AdministrativePriority::from($data['administrative_priority']);
            $queueEntry = $encounter->queueEntries()->create([
                'queue_id' => $queue->getKey(),
                'ticket_number' => $this->tickets->next($queue),
                'priority_weight' => $priority === AdministrativePriority::None ? 0 : 10,
                'status' => QueueEntryStatus::Waiting,
                'entered_at' => $now,
            ]);
            $queueEntry->history()->create([
                'from_status' => null,
                'to_status' => QueueEntryStatus::Waiting,
                'action' => 'entered',
                'performed_by' => $user->getKey(),
                'reason' => 'Entrada inicial pela recepção',
                'occurred_at' => $now,
            ]);

            $this->createExamOrder->execute($encounter, $data, $user, $unit, $request);

            IdempotencyKey::query()->create([
                'user_id' => $user->getKey(),
                'route_name' => 'reception.store',
                'idempotency_key' => $data['idempotency_key'],
                'request_hash' => $requestHash,
                'response_status' => 201,
                'response_body' => ['encounter_public_id' => $encounter->public_id],
                'expires_at' => $now->addDay(),
            ]);

            $this->audit->execute(
                'encounter.opened',
                $request,
                $user,
                ['ticket' => $queueEntry->ticket_number, 'queue' => $queue->code],
                (int) $unit->getKey(),
                (int) $patient->getKey(),
                (int) $encounter->getKey(),
            );

            return $encounter->load(['patient.identifiers', 'healthUnit', 'receptionRecord', 'queueEntries.queue']);
        });
    }
}
