<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SaveMedicalDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.start') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $text = ['nullable', 'string', 'max:8000'];

        return [
            'version' => ['required', 'integer', 'min:1'],
            'chief_complaint' => $text,
            'present_illness_history' => $text,
            'personal_history' => $text,
            'family_history' => $text,
            'surgical_history' => $text,
            'current_medications' => $text,
            'allergies_summary' => $text,
            'habits' => $text,
            'gynecological_history' => $text,
            'review_of_systems' => $text,
            'additional_notes' => $text,
            'conduct_summary' => $text,
            'procedures_summary' => $text,
            'guidance' => $text,
            'requires_reassessment' => ['nullable', 'boolean'],
            'physical_exam_justification' => $text,
            'general_state' => $text,
            'consciousness' => $text,
            'skin_mucosa' => $text,
            'head_neck' => $text,
            'respiratory' => $text,
            'cardiovascular' => $text,
            'abdomen' => $text,
            'neurological' => $text,
            'musculoskeletal' => $text,
            'extremities' => $text,
            'specific_findings' => $text,
            'free_text' => $text,
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['requires_reassessment' => $this->boolean('requires_reassessment')]);
    }
}
