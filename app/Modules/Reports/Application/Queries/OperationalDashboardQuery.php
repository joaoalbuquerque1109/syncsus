<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Queries;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Medical\Infrastructure\Eloquent\EncounterDestination;
use App\Modules\Queues\Domain\Enums\QueueEntryStatus;
use App\Modules\Reception\Domain\Enums\EncounterStatus;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Support\Facades\Cache;

final class OperationalDashboardQuery
{
    /** @return array<string, int|string> */
    public function metrics(HealthUnit $unit): array
    {
        if (! config('sync_sus.performance_cache.enabled', true)) {
            return $this->freshMetrics($unit);
        }

        $key = sprintf(
            'syncsus:organization:%d:unit:%d:dashboard:metrics',
            $unit->organization_id,
            $unit->getKey(),
        );

        return Cache::remember(
            $key,
            (int) config('sync_sus.performance_cache.dashboard_seconds', 3),
            fn (): array => $this->freshMetrics($unit),
        );
    }

    /** @return array<string, int|string> */
    private function freshMetrics(HealthUnit $unit): array
    {
        $activeStatuses = [
            EncounterStatus::WaitingTriage,
            EncounterStatus::InTriage,
            EncounterStatus::WaitingMedical,
            EncounterStatus::InMedicalCare,
            EncounterStatus::UnderObservation,
            EncounterStatus::AwaitingAdmission,
        ];
        $todayStart = today()->startOfDay();
        $tomorrowStart = $todayStart->copy()->addDay();
        $bindings = array_map(
            static fn (EncounterStatus $status): string => $status->value,
            $activeStatuses,
        );
        $countRow = Encounter::query()
            ->where('health_unit_id', $unit->getKey())
            ->where(function ($query) use ($bindings, $todayStart, $tomorrowStart): void {
                $query->whereIn('current_status', $bindings)
                    ->orWhere(function ($query) use ($todayStart, $tomorrowStart): void {
                        $query->where('current_status', EncounterStatus::Discharged)
                            ->where('closed_at', '>=', $todayStart)
                            ->where('closed_at', '<', $tomorrowStart);
                    });
            })
            ->selectRaw(
                <<<'SQL'
                COALESCE(SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END), 0) AS waiting_triage,
                COALESCE(SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END), 0) AS in_triage,
                COALESCE(SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END), 0) AS waiting_medical,
                COALESCE(SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END), 0) AS in_medical_care,
                COALESCE(SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END), 0) AS under_observation,
                COALESCE(SUM(CASE WHEN current_status = ? THEN 1 ELSE 0 END), 0) AS awaiting_admission,
                COALESCE(SUM(CASE WHEN current_status = ? AND closed_at >= ? AND closed_at < ? THEN 1 ELSE 0 END), 0) AS discharges_today
                SQL,
                [
                    ...$bindings,
                    EncounterStatus::Discharged->value,
                    $todayStart,
                    $tomorrowStart,
                ],
            )
            ->toBase()
            ->first();
        $counts = $countRow === null ? [] : get_object_vars($countRow);

        return [
            'waiting_triage' => (int) ($counts['waiting_triage'] ?? 0),
            'in_triage' => (int) ($counts['in_triage'] ?? 0),
            'waiting_medical' => (int) ($counts['waiting_medical'] ?? 0),
            'in_medical_care' => (int) ($counts['in_medical_care'] ?? 0),
            'under_observation' => (int) ($counts['under_observation'] ?? 0),
            'awaiting_admission' => (int) ($counts['awaiting_admission'] ?? 0),
            'transfers_today' => EncounterDestination::query()
                ->whereHas('encounter', fn ($query) => $query->where('health_unit_id', $unit->getKey()))
                ->where('destination_type', 'transfer')
                ->where('occurred_at', '>=', $todayStart)
                ->where('occurred_at', '<', $tomorrowStart)
                ->count(),
            'discharges_today' => (int) ($counts['discharges_today'] ?? 0),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** @return list<array<string, int|string|null>> */
    public function activeEncounters(HealthUnit $unit, bool $showPatientNames): array
    {
        $encounters = Encounter::query()
            ->with([
                'patient',
                'riskLevel',
                'currentRoom',
                'currentDepartment',
                'queueEntries' => fn ($query) => $query
                    ->whereIn('status', [
                        QueueEntryStatus::Waiting,
                        QueueEntryStatus::Called,
                        QueueEntryStatus::InService,
                    ])
                    ->orderByDesc('entered_at'),
            ])
            ->where('health_unit_id', $unit->getKey())
            ->whereNotIn('current_status', [
                EncounterStatus::Admitted, EncounterStatus::Discharged, EncounterStatus::Transferred,
                EncounterStatus::LeftWithoutNotice, EncounterStatus::Deceased, EncounterStatus::Cancelled,
            ])
            ->orderBy('arrival_at')
            ->limit(20)
            ->get();

        return $encounters->map(function (Encounter $encounter) use ($showPatientNames): array {
            $entry = $encounter->queueEntries->first();

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
