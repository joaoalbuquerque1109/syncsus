<?php

declare(strict_types=1);

namespace App\Modules\Triage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreVitalSignsRequest extends FormRequest
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
            'measured_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->format('Y-m-d H:i:s')],
            'systolic_bp' => ['nullable', 'integer'],
            'diastolic_bp' => ['nullable', 'integer'],
            'heart_rate' => ['nullable', 'integer'],
            'respiratory_rate' => ['nullable', 'integer'],
            'temperature_c' => ['nullable', 'numeric'],
            'oxygen_saturation' => ['nullable', 'numeric'],
            'blood_glucose' => ['nullable', 'integer'],
            'weight_kg' => ['nullable', 'numeric'],
            'height' => ['nullable', 'numeric'],
            'height_unit' => ['required', Rule::in(['cm', 'm'])],
            'pain_scale' => ['nullable', 'integer'],
            'glasgow_score' => ['nullable', 'integer'],
            'blood_type' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'circumference_cm' => ['nullable', 'numeric'],
            'clinical_alerts' => ['nullable', 'array'],
            'clinical_alerts.*' => ['string', Rule::in([
                'known_hypertension', 'diabetes', 'suspected_sepsis', 'dyspnea',
                'active_bleeding', 'arrhythmia', 'medication_allergy',
                'external_medication', 'oxygen_in_use', 'invasive_device',
            ])],
            'confirm_outside_ranges' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $fields = [
                    'systolic_bp', 'diastolic_bp', 'heart_rate', 'respiratory_rate',
                    'temperature_c', 'oxygen_saturation', 'blood_glucose', 'weight_kg',
                    'height', 'pain_scale', 'glasgow_score', 'blood_type', 'circumference_cm',
                ];
                if (collect($fields)->every(fn (string $field): bool => blank($this->input($field)))) {
                    $validator->errors()->add('vital_signs', 'Informe ao menos um sinal vital.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['confirm_outside_ranges' => $this->boolean('confirm_outside_ranges')]);
    }
}
