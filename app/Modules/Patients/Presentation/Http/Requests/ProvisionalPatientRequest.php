<?php

declare(strict_types=1);

namespace App\Modules\Patients\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ProvisionalPatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('patients.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'full_name' => ['nullable', 'string', 'max:255'],
            'sex' => ['required', Rule::in(['female', 'male', 'intersex', 'unknown'])],
            'estimated_age' => ['nullable', 'integer', 'between:0,130'],
            'estimated_age_range' => ['nullable', Rule::in(['infant', 'child', 'adolescent', 'adult', 'elderly', 'unknown'])],
            'provisional_description' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }
}
