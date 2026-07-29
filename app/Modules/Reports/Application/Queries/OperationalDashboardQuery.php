<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Queries;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Queues\Infrastructure\Eloquent\QueueEntry;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;

final class OperationalDashboardQuery
{
    /** @return array<string, int|string> */
    public function metrics(HealthUnit $unit): array
    {
        $base = Encounter::query()->where('health_unit_id', $unit->getKey());

        return [
            'waiting_triage' => (clone $base)->where('current_status', EncounterStatus::WaitingTriage)->count(),
            'in_triage' => (clone $base)->where('current_status', EncounterStatus::InTriage)->count(),
            'waiting_medical' => (clone $base)->where('current_status', EncounterStatus::WaitingMedical)->count(),
            'in_medical_care' => (clone $base)->where('current_status', EncounterStatus::InMedicalCare)->count(),
            'under_observation' => (clone $base)->where('current_status', EncounterStatus::UnderObservation)->count(),
            'awaiting_admission' => (clone $base)->where('current_status', EncounterStatus::AwaitingAdmission)->count(),
            'transfers_today' => EncounterDestination::query()
                ->whereHas('encounter', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->where('destination_type', 'transfer')
                ->whereDate('occurred_at', today())
                ->count(),
            'discharges_today' => (clone $base)
                ->where('current_status', EncounterStatus::Discharged)
                ->whereDate('closed_at', today())
                ->count(),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** @return list<array<string, int|string|null>> */
    public function activeEncounters(HealthUnit $unit, bool $showPatientNames): array
    {
        $encounters = Encounter::query()
            ->with(['patient', 'riskLevel', 'currentRoom', 'currentDepartment', 'queueEntries'])
            ->where('health_unit_id', $unit->getKey())
            ->whereNotIn('current_status', [
                EncounterStatus::Admitted, EncounterStatus::Discharged, EncounterStatus::Transferred,
                EncounterStatus::LeftWithoutNotice, EncounterStatus::Deceased, EncounterStatus::Cancelled,
            ])
            ->orderBy('arrival_at')
            ->limit(20)
            ->get();

        return $encounters->map(function (Encounter $encounter) use ($showPatientNames): array {
            $entry = $encounter->queueEntries
                ->filter(fn (QueueEntry $queueEntry): bool => in_array(
                    $queueEntry->statusEnum(),
                    [
                        QueueEntryStatus::Waiting,
                        QueueEntryStatus::Called,
                        QueueEntryStatus::InService,
                    ],
                    true,
                ))
                ->sortByDesc('entered_at')
                ->first();

            return [
                'encounter' => $encounter->public_id,
                'ticket' => $entry->ticket_number ?? '—',
                'patient' => $showPatientNames
                    ? $encounter->patient->displayName()
                    : $this->maskedName($encounter->patient->displayName()),
                'stage' => $this->statusLabel($encounter->currentStatusEnum()),
                'risk' => $encounter->riskLevel->name ?? null,
                'risk_color' => $encounter->riskLevel->color_key ?? null,
                'waiting_minutes' => $entry === null ? null : max(0, (int) $entry->enteredAt()->diffInMinutes(now())),
                'location' => $encounter->currentRoom->name ?? $encounter->currentDepartment->name ?? null,
            ];
        })->all();
    }

    private function maskedName(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])
            ->filter()
            ->map(fn (string $part): string => mb_substr($part, 0, 1).'***')
            ->join(' ');
    }

    private function statusLabel(EncounterStatus $status): string
    {
        return match ($status) {
            EncounterStatus::Opened => 'Aberto',
            EncounterStatus::WaitingTriage => 'Aguardando triagem',
            EncounterStatus::CalledToTriage => 'Chamado para triagem',
            EncounterStatus::InTriage => 'Em triagem',
            EncounterStatus::WaitingMedical => 'Aguardando médico',
            EncounterStatus::CalledToMedical => 'Chamado para médico',
            EncounterStatus::InMedicalCare => 'Em atendimento',
            EncounterStatus::WaitingExam => 'Aguardando exame',
            EncounterStatus::WaitingProcedure => 'Aguardando procedimento',
            EncounterStatus::UnderObservation => 'Em observação',
            EncounterStatus::AwaitingAdmission => 'Aguardando internação',
            EncounterStatus::AwaitingTransfer => 'Aguardando transferência',
            default => str($status->value)->replace('_', ' ')->title()->toString(),
        };
    }
}
