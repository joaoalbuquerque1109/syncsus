<?php

declare(strict_types=1);

namespace App\Modules\Triage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveTriageDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('triage.start') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'chief_complaint' => ['nullable', 'string', 'max:4000'],
            'symptom_onset' => ['nullable', 'string', 'max:255'],
            'brief_history' => ['nullable', 'string', 'max:8000'],
            'pain_scale' => ['nullable', 'integer', 'between:0,10'],
            'has_reported_allergies' => ['nullable', 'boolean'],
            'reported_allergies' => ['nullable', 'required_if:has_reported_allergies,1', 'string', 'max:4000'],
            'uses_medications' => ['nullable', 'boolean'],
            'current_medications' => ['nullable', 'required_if:uses_medications,1', 'string', 'max:4000'],
            'known_conditions' => ['nullable', 'string', 'max:4000'],
            'pregnancy_status' => ['nullable', Rule::in(['not_applicable', 'denies', 'confirmed', 'suspected', 'unknown'])],
            'fall_risk' => ['nullable', Rule::in(['low', 'moderate', 'high', 'not_assessed'])],
            'requires_isolation' => ['nullable', 'boolean'],
            'isolation_reason' => ['nullable', 'required_if:requires_isolation,1', 'string', 'max:255'],
            'violence_signs' => ['nullable', 'boolean'],
            'violence_notes' => ['nullable', 'required_if:violence_signs,1', 'string', 'max:4000'],
            'initial_exam' => ['nullable', 'string', 'max:8000'],
            'observations' => ['nullable', 'string', 'max:8000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['has_reported_allergies', 'uses_medications', 'requires_isolation', 'violence_signs'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => $this->boolean($field)]);
            }
        }
    }
}
