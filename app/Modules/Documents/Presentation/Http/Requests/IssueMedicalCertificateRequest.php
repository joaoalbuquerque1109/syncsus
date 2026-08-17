<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueMedicalCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.issue_documents') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'starts_at' => ['required', 'date'],
            'duration_value' => ['required', 'integer', 'min:1', 'max:365'],
            'duration_unit' => ['required', Rule::in(['hours', 'days'])],
            'statement' => ['required', 'string', 'min:10', 'max:8000'],
            'additional_information' => ['nullable', 'string', 'max:4000'],
            'include_cid' => ['nullable', 'boolean'],
            'cid_code_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->boolean('include_cid')),
                'integer',
                Rule::exists('core.diagnosis_codes', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'cid_authorization' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->boolean('include_cid')),
                'accepted_if:include_cid,1',
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['include_cid' => $this->boolean('include_cid')]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cid_code_id.required' => 'Selecione um CID do catálogo para incluí-lo no atestado.',
            'cid_code_id.exists' => 'O CID selecionado não está disponível no catálogo.',
            'cid_authorization.required' => 'Confirme a autorização expressa do paciente para incluir o CID.',
            'cid_authorization.accepted' => 'Confirme a autorização expressa do paciente para incluir o CID.',
            'cid_authorization.accepted_if' => 'Confirme a autorização expressa do paciente para incluir o CID.',
        ];
    }
}
