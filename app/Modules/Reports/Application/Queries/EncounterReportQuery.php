<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Queries;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

final class EncounterReportQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function run(HealthUnit $unit, array $filters, bool $showPatientNames): array
    {
        $query = $this->query($unit, $filters);
        $encounters = $query->orderByDesc('arrival_at')->limit(1000)->get();
        $rows = $encounters->map(fn (Encounter $encounter): array => $this->row($encounter, $showPatientNames))->all();
        $totalMinutes = collect($rows)->pluck('total_minutes')->filter(fn (mixed $value): bool => is_int($value));
        $triageWait = collect($rows)->pluck('arrival_to_triage_minutes')->filter(fn (mixed $value): bool => is_int($value));
        $medicalWait = collect($rows)->pluck('triage_to_medical_minutes')->filter(fn (mixed $value): bool => is_int($value));

        return [
            'summary' => [
                'total' => count($rows),
                'average_total_minutes' => $totalMinutes->isEmpty() ? null : round((float) $totalMinutes->average(), 1),
                'average_triage_wait_minutes' => $triageWait->isEmpty() ? null : round((float) $triageWait->average(), 1),
                'average_medical_wait_minutes' => $medicalWait->isEmpty() ? null : round((float) $medicalWait->average(), 1),
                'by_status' => collect($rows)->countBy('status')->sortDesc()->all(),
                'by_risk' => collect($rows)->countBy(fn (array $row): string => (string) ($row['risk'] ?? 'Sem classificação'))->sortDesc()->all(),
                'by_destination' => collect($rows)->countBy(fn (array $row): string => (string) ($row['destination'] ?? 'Sem destinação'))->sortDesc()->all(),
            ],
            'rows' => $rows,
        ];
    }

    /** @param array<string, mixed> $filters
     * @return Builder<Encounter>
     */
    private function query(HealthUnit $unit, array $filters): Builder
    {
        $query = Encounter::query()
            ->with([
                'patient', 'entryType', 'arrivalMethod', 'riskLevel', 'assignedSpecialty',
                'medicalConsultation.professional', 'destination',
            ])
            ->where('health_unit_id', $unit->getKey())
            ->whereBetween('arrival_at', [
                Carbon::parse((string) $filters['date_from'])->startOfDay(),
                Carbon::parse((string) $filters['date_to'])->endOfDay(),
            ]);
        if (filled($filters['status'] ?? null)) {
            $query->where('current_status', $filters['status']);
        }
        if (filled($filters['risk_level_id'] ?? null)) {
            $query->where('risk_level_id', $filters['risk_level_id']);
        }
        if (filled($filters['specialty_id'] ?? null)) {
            $query->where('assigned_specialty_id', $filters['specialty_id']);
        }
        if (filled($filters['professional_id'] ?? null)) {
            $query->whereHas('medicalConsultation', fn ($query) => $query->where('professional_id', $filters['professional_id']));
        }
        if (filled($filters['destination_type'] ?? null)) {
            $query->whereHas('destination', fn ($query) => $query->where('destination_type', $filters['destination_type']));
        }

        return $query;
    }

    /** @return array<string, mixed> */
    private function row(Encounter $encounter, bool $showPatientNames): array
    {
        $arrivalAt = $encounter->arrivalAt();
        $triageStartedAt = $encounter->triageStartedAt();
        $triageFinishedAt = $encounter->triageFinishedAt();
        $medicalStartedAt = $encounter->medicalStartedAt();
        $medicalFinishedAt = $encounter->medicalFinishedAt();
        $end = $encounter->closedAt() ?? now();

        return [
            'encounter' => $encounter->encounter_number,
            'patient' => $showPatientNames ? $encounter->patient->displayName() : $this->maskedName($encounter->patient->displayName()),
            'medical_record' => $showPatientNames ? $encounter->patient->medical_record_number : $this->maskedRecord((string) $encounter->patient->medical_record_number),
            'age' => $encounter->patient->ageYears(),
            'sex' => $encounter->patient->sexEnum()->label(),
            'arrival_at' => $arrivalAt->format('d/m/Y H:i'),
            'entry_type' => $encounter->entryType->name,
            'arrival_method' => $encounter->arrivalMethod->name,
            'risk' => $encounter->riskLevel?->name,
            'specialty' => $encounter->assignedSpecialty?->name,
            'professional' => $encounter->medicalConsultation?->professional?->name,
            'status' => $encounter->currentStatusEnum()->value,
            'destination' => $encounter->destination?->typeEnum()->label(),
            'arrival_to_triage_minutes' => $triageStartedAt === null ? null : max(0, (int) $arrivalAt->diffInMinutes($triageStartedAt)),
            'triage_duration_minutes' => $triageStartedAt === null || $triageFinishedAt === null ? null : max(0, (int) $triageStartedAt->diffInMinutes($triageFinishedAt)),
            'triage_to_medical_minutes' => $triageFinishedAt === null || $medicalStartedAt === null ? null : max(0, (int) $triageFinishedAt->diffInMinutes($medicalStartedAt)),
            'medical_duration_minutes' => $medicalStartedAt === null || $medicalFinishedAt === null ? null : max(0, (int) $medicalStartedAt->diffInMinutes($medicalFinishedAt)),
            'total_minutes' => max(0, (int) $arrivalAt->diffInMinutes($end)),
        ];
    }

    private function maskedName(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()
            ->map(fn (string $part): string => mb_substr($part, 0, 1).'***')->join(' ');
    }

    private function maskedRecord(string $record): string
    {
        return mb_strlen($record) <= 4 ? '****' : str_repeat('*', mb_strlen($record) - 4).mb_substr($record, -4);
    }
}
