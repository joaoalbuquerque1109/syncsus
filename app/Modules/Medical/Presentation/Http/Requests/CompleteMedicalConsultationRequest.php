<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CompleteMedicalConsultationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.complete') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'destination_type' => ['required', Rule::in(['discharge', 'observation', 'admission_request', 'transfer', 'evasion', 'death'])],
            'reason' => ['required', 'string', 'min:5', 'max:4000'],
            'clinical_condition' => ['nullable', 'string', 'max:4000'],
            'clinical_summary' => ['nullable', 'string', 'max:8000'],
            'instructions' => ['nullable', 'required_if:destination_type,discharge', 'string', 'max:8000'],
            'warning_signs' => ['nullable', 'required_if:destination_type,discharge', 'string', 'max:4000'],
            'follow_up' => ['nullable', 'string', 'max:255'],
            'destination_institution' => ['nullable', 'required_if:destination_type,transfer', 'string', 'max:255'],
            'destination_city' => ['nullable', 'required_if:destination_type,transfer', 'string', 'max:255'],
            'destination_department' => ['nullable', 'required_if:destination_type,observation,admission_request,transfer', 'string', 'max:255'],
            'bed_type' => ['nullable', 'required_if:destination_type,admission_request', 'string', 'max:128'],
            'transport_method' => ['nullable', 'required_if:destination_type,transfer', 'string', 'max:128'],
            'last_known_location' => ['nullable', 'required_if:destination_type,evasion', 'string', 'max:255'],
            'contact_attempts' => ['nullable', 'required_if:destination_type,evasion', 'string', 'max:4000'],
            'death_cause' => ['nullable', 'required_if:destination_type,death', 'string', 'max:4000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->format('Y-m-d H:i:s')],
            'professional_confirmation' => ['accepted'],
        ];
    }
}
