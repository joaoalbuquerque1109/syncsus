<?php

declare(strict_types=1);

namespace App\Modules\Audit\Application\Queries;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Audit\Infrastructure\Eloquent\AuditLog;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Patients\Infrastructure\Eloquent\PatientAccessLog;
use App\Modules\Reception\Infrastructure\Eloquent\Encounter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

final class AuditTrailQuery
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function events(HealthUnit $unit, array $filters): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->with('encounter')
            ->where('health_unit_id', $unit->getKey());
        $this->applyCommonFilters($query, $filters);
        if (filled($filters['action'] ?? null)) {
            $query->where('action', (string) $filters['action']);
        }

        $paginator = $query->latest('occurred_at')->paginate(50, ['*'], 'audit_page')->withQueryString();
        $this->hydrateCoreRelations($paginator);

        return $paginator;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, PatientAccessLog>
     */
    public function accesses(HealthUnit $unit, array $filters): LengthAwarePaginator
    {
        $query = PatientAccessLog::query()
            ->with('encounter')
            ->where('health_unit_id', $unit->getKey());
        $this->applyCommonFilters($query, $filters);
        if (filled($filters['access_type'] ?? null)) {
            $query->where('access_type', (string) $filters['access_type']);
        }

        $paginator = $query->latest('occurred_at')->paginate(50, ['*'], 'access_page')->withQueryString();
        $this->hydrateCoreRelations($paginator);

        return $paginator;
    }

    /** @param array<string, mixed> $filters
     * @return array{events: int, record_accesses: int, login_failures: int, exports: int}
     */
    public function summary(HealthUnit $unit, array $filters): array
    {
        $period = [
            Carbon::parse((string) $filters['date_from'])->startOfDay(),
            Carbon::parse((string) $filters['date_to'])->endOfDay(),
        ];
        $events = AuditLog::query()->where('health_unit_id', $unit->getKey())->whereBetween('occurred_at', $period);
        $accesses = PatientAccessLog::query()->where('health_unit_id', $unit->getKey())->whereBetween('occurred_at', $period);

        return [
            'events' => (clone $events)->count(),
            'record_accesses' => $accesses->count(),
            'login_failures' => (clone $events)->where('action', 'user.login_failed')->count(),
            'exports' => (clone $events)->where('action', 'report.exported')->count(),
        ];
    }

    /** @return list<string> */
    public function actions(HealthUnit $unit): array
    {
        return AuditLog::query()
            ->where('health_unit_id', $unit->getKey())
            ->distinct()
            ->orderBy('action')
            ->pluck('action')
            ->all();
    }

    /** @return list<string> */
    public function accessTypes(HealthUnit $unit): array
    {
        return PatientAccessLog::query()
            ->where('health_unit_id', $unit->getKey())
            ->distinct()
            ->orderBy('access_type')
            ->pluck('access_type')
            ->all();
    }

    /** @param Builder<AuditLog>|Builder<PatientAccessLog> $query
     * @param  array<string, mixed>  $filters
     */
    private function applyCommonFilters(Builder $query, array $filters): void
    {
        $query->whereBetween('occurred_at', [
            Carbon::parse((string) $filters['date_from'])->startOfDay(),
            Carbon::parse((string) $filters['date_to'])->endOfDay(),
        ]);
        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', $filters['user_id']);
        }
        if (filled($filters['patient'] ?? null)) {
            $term = (string) $filters['patient'];
            $patientIds = Patient::query()
                ->where('public_id', $term)
                ->orWhere('medical_record_number', $term)
                ->pluck('id')
                ->all();
            $query->whereIn('patient_id', $patientIds);
        }
        if (filled($filters['encounter'] ?? null)) {
            $term = (string) $filters['encounter'];
            $encounterIds = Encounter::query()
                ->where('public_id', $term)
                ->orWhere('encounter_number', $term)
                ->pluck('id')
                ->all();
            $query->whereIn('encounter_id', $encounterIds);
        }
    }

    /**
     * @template TRecord of AuditLog|PatientAccessLog
     *
     * @param  LengthAwarePaginator<int, TRecord>  $paginator
     */
    private function hydrateCoreRelations(LengthAwarePaginator $paginator): void
    {
        $records = $paginator->getCollection();
        $users = User::query()
            ->whereKey($records->pluck('user_id')->filter()->unique()->all())
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->getKey());
        $patients = Patient::query()
            ->whereKey($records->pluck('patient_id')->filter()->unique()->all())
            ->get()
            ->keyBy(fn (Patient $patient): int => (int) $patient->getKey());

        foreach ($records as $record) {
            $record->setRelation('user', $users->get((string) $record->user_id));
            $record->setRelation('patient', $patients->get((int) $record->patient_id));
        }
    }
}
