<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreClinicalNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.start') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'note_type' => ['required', Rule::in(['medical_evolution', 'reassessment', 'intercurrence'])],
            'content' => ['required', 'string', 'min:10', 'max:12000'],
            'clinical_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->format('Y-m-d H:i:s')],
            'parent_note_id' => ['nullable', 'integer', 'exists:clinical_notes,id'],
            'addendum_reason' => ['nullable', 'required_with:parent_note_id', 'string', 'min:5', 'max:255'],
        ];
    }
}
