<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDiagnosisRequest extends FormRequest
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
            'diagnosis_code_id' => [
                'nullable',
                'integer',
                Rule::exists('diagnosis_codes', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'description' => ['nullable', 'required_without:diagnosis_code_id', 'string', 'min:3', 'max:255'],
            'diagnosis_type' => ['required', Rule::in(['hypothesis', 'confirmed', 'ruled_out'])],
            'is_primary' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_primary' => $this->boolean('is_primary')]);
    }
}
