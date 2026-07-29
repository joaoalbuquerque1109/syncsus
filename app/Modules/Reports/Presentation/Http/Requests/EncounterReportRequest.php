<?php

declare(strict_types=1);

namespace App\Modules\Reports\Presentation\Http\Requests;

use App\Modules\Reception\Domain\Enums\EncounterStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class EncounterReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('reports.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', Rule::enum(EncounterStatus::class)],
            'risk_level_id' => ['nullable', 'integer', 'exists:risk_levels,id'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'professional_id' => ['nullable', 'integer', 'exists:users,id'],
            'destination_type' => ['nullable', Rule::in(['discharge', 'observation', 'admission_request', 'transfer', 'evasion', 'death'])],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->date('date_from') || ! $this->date('date_to')) {
                    return;
                }
                if ($this->date('date_from')->diffInDays($this->date('date_to')) > 366) {
                    $validator->errors()->add('date_to', 'O período máximo permitido é de 366 dias.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', today()->subDays(30)->toDateString()),
            'date_to' => $this->input('date_to', today()->toDateString()),
        ]);
    }
}
