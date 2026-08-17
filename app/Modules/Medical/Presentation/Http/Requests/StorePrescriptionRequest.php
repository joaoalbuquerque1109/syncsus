<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePrescriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.prescribe') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'replaces_prescription_id' => ['nullable', 'ulid', 'exists:prescriptions,public_id'],
            'replacement_reason' => ['nullable', 'required_with:replaces_prescription_id', 'string', 'min:10', 'max:2000'],
            'prescription_type' => ['required', Rule::in(['hospital', 'home'])],
            'general_instructions' => ['nullable', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.medication_name' => ['required', 'string', 'max:255'],
            'items.*.presentation' => ['required', 'string', 'max:255'],
            'items.*.concentration' => ['nullable', 'string', 'max:128'],
            'items.*.dose' => ['required', 'numeric', 'gt:0'],
            'items.*.dose_unit' => ['required', 'string', 'max:32'],
            'items.*.route' => ['required', 'string', 'max:64'],
            'items.*.frequency' => ['required', 'string', 'max:128'],
            'items.*.interval_text' => ['nullable', 'string', 'max:128'],
            'items.*.duration_value' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'items.*.duration_unit' => ['nullable', 'required_with:items.*.duration_value', 'string', 'max:32'],
            'items.*.quantity' => ['nullable', 'string', 'max:64'],
            'items.*.instructions' => ['nullable', 'string', 'max:4000'],
            'items.*.dilution' => ['nullable', 'string', 'max:1000'],
            'items.*.infusion_rate' => ['nullable', 'string', 'max:255'],
        ];
    }
}
