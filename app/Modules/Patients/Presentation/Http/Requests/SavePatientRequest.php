<?php

declare(strict_types=1);

namespace App\Modules\Patients\Presentation\Http\Requests;

use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Rules\ValidCpf;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SavePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'patients.create' : 'patients.update') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $patient = $this->route('patient');
        $patientId = $patient instanceof Patient ? $patient->getKey() : null;

        return [
            'idempotency_key' => [
                Rule::requiredIf($this->route('patient') === null),
                'nullable',
                'string',
                'max:64',
            ],
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'social_name' => ['nullable', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before_or_equal:today'],
            'sex' => ['required', Rule::in(['female', 'male', 'intersex', 'unknown'])],
            'cpf' => ['nullable', new ValidCpf, Rule::unique('core.patient_identifiers', 'normalized_value')->where('type', 'cpf')->ignore($patientId, 'patient_id')],
            'cns' => ['nullable', 'digits:15', Rule::unique('core.patient_identifiers', 'normalized_value')->where('type', 'cns')->ignore($patientId, 'patient_id')],
            'rg' => ['nullable', 'string', 'max:32', Rule::unique('core.patient_identifiers', 'normalized_value')->where('type', 'rg')->ignore($patientId, 'patient_id')],
            'rg_issuer' => ['nullable', 'string', 'max:64'],
            'rg_issuer_state' => ['nullable', 'string', 'size:2'],
            'rg_issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'passport' => ['nullable', 'string', 'max:32', Rule::unique('core.patient_identifiers', 'normalized_value')->where('type', 'passport')->ignore($patientId, 'patient_id')],
            'passport_issuer' => ['nullable', 'string', 'max:64'],
            'passport_issuer_state' => ['nullable', 'string', 'size:2'],
            'passport_issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'mother_name' => ['nullable', 'required_unless:mother_unknown,1', 'string', 'max:255'],
            'mother_unknown' => ['nullable', 'boolean'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'father_unknown' => ['nullable', 'boolean'],
            'gender_identity' => ['nullable', 'string', 'max:64'],
            'race_color' => ['nullable', 'string', 'max:32'],
            'nationality' => ['nullable', 'string', 'max:64'],
            'birth_city' => ['nullable', 'string', 'max:120'],
            'birth_city_ibge_code' => ['nullable', 'string', 'max:16'],
            'ethnicity' => ['nullable', 'string', 'max:64'],
            'marital_status' => ['nullable', 'string', 'max:32'],
            'number_of_children' => ['nullable', 'integer', 'min:0', 'max:30'],
            'is_disabled' => ['nullable', 'boolean'],
            'blood_type' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'education_level' => ['nullable', 'string', 'max:64'],
            'occupation' => ['nullable', 'string', 'max:120'],
            'administrative_notes' => ['nullable', 'string', 'max:3000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone2' => ['nullable', 'string', 'max:32'],
            'phone3' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'city_ibge_code' => ['nullable', 'string', 'max:16'],
            'district' => ['nullable', 'string', 'max:120'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:32'],
            'complement' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:255'],
            'area_type' => ['nullable', Rule::in(['urban', 'rural'])],
            'address_unknown' => ['nullable', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_cpf' => ['nullable', new ValidCpf],
            'guardian_relationship' => ['nullable', 'required_with:guardian_name', 'string', 'max:64'],
            'guardian_phone' => ['nullable', 'string', 'max:32'],
            'guardian_reason' => ['nullable', 'string', 'max:255'],
            'financial_guardian_name' => ['nullable', 'string', 'max:255'],
            'financial_guardian_cpf' => ['nullable', new ValidCpf],
            'financial_guardian_relationship' => ['nullable', 'required_with:financial_guardian_name', 'string', 'max:64'],
            'financial_guardian_phone' => ['nullable', 'string', 'max:32'],
            'financial_guardian_reason' => ['nullable', 'string', 'max:255'],
            'return_to_reception' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf' => NormalizesBrazilianData::digits($this->input('cpf')),
            'cns' => NormalizesBrazilianData::digits($this->input('cns')),
            'rg' => $this->normalizeDocument($this->input('rg')),
            'passport' => $this->normalizeDocument($this->input('passport')),
            'guardian_cpf' => NormalizesBrazilianData::digits($this->input('guardian_cpf')),
            'financial_guardian_cpf' => NormalizesBrazilianData::digits($this->input('financial_guardian_cpf')),
            'mother_unknown' => $this->boolean('mother_unknown'),
            'father_unknown' => $this->boolean('father_unknown'),
            'is_disabled' => $this->boolean('is_disabled'),
            'address_unknown' => $this->boolean('address_unknown'),
            'return_to_reception' => $this->boolean('return_to_reception'),
        ]);
    }

    private function normalizeDocument(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = mb_strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $value));

        return $normalized !== '' ? $normalized : null;
    }
}
