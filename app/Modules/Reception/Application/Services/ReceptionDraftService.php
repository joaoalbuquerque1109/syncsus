<?php

declare(strict_types=1);

namespace App\Modules\Reception\Application\Services;

use Illuminate\Http\Request;

final class ReceptionDraftService
{
    /** @var list<string> */
    private const FIELDS = [
        'idempotency_key',
        'entry_type_id',
        'arrival_method_id',
        'arrival_at',
        'origin',
        'entry_reason',
        'administrative_priority',
        'vehicle_information',
        'reception_notes',
        'department_id',
        'queue_id',
        'specialty_id',
        'request_exams',
        'exam_requester_id',
        'exam_priority',
        'exam_clinical_indication',
        'exam_notes',
        'exam_ids',
        'companion_name',
        'companion_cpf',
        'companion_phone',
        'companion_relationship',
        'companion_is_guardian',
        '_reception_step',
    ];

    public function store(Request $request, int $healthUnitId): void
    {
        $draft = $request->only(self::FIELDS);
        $draft['request_exams'] = $request->boolean('request_exams');
        $draft['companion_is_guardian'] = $request->boolean('companion_is_guardian');
        $draft['_reception_step'] = min(3, max(1, $request->integer('_reception_step', 2)));
        $draft['exam_ids'] = collect((array) ($draft['exam_ids'] ?? []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(30)
            ->values()
            ->all();

        $request->session()->put($this->key($healthUnitId), $draft);
    }

    /** @return array<string, mixed> */
    public function pull(Request $request, int $healthUnitId): array
    {
        $draft = $request->session()->pull($this->key($healthUnitId), []);

        return is_array($draft) ? $draft : [];
    }

    private function key(int $healthUnitId): string
    {
        return "reception.draft.{$healthUnitId}";
    }
}
