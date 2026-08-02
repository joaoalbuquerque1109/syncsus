<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Requests;

use App\Modules\Documents\Domain\Enums\ClinicalDocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IssueClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.issue_documents') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'document_type' => ['required', Rule::in([
                ...array_map(
                    static fn (ClinicalDocumentType $type): string => $type->value,
                    ClinicalDocumentType::manuallyIssued(),
                ),
            ])],
            'body' => ['required', 'string', 'min:10', 'max:16000'],
            'recipient_name' => ['nullable', 'required_if:document_type,companion_declaration', 'string', 'max:255'],
            'starts_at' => ['nullable', 'required_if:document_type,attendance_declaration,companion_declaration', 'date'],
            'duration_value' => ['nullable', 'required_if:document_type,attendance_declaration,companion_declaration', 'integer', 'min:1', 'max:365'],
            'duration_unit' => ['nullable', 'required_with:duration_value', Rule::in(['hours', 'days'])],
            'include_cid' => ['nullable', 'boolean'],
            'cid_code_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->boolean('include_cid')),
                'integer',
                Rule::exists('diagnosis_codes', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'cid_authorization' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $this->boolean('include_cid')),
                'accepted_if:include_cid,1',
            ],
            'additional_information' => ['nullable', 'string', 'max:4000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'include_cid' => $this->input('document_type') === ClinicalDocumentType::MedicalReport->value
                && $this->boolean('include_cid'),
        ]);
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cid_code_id.required' => 'Selecione um CID do catálogo para incluí-lo no documento.',
            'cid_code_id.exists' => 'O CID selecionado não está disponível no catálogo.',
            'cid_authorization.required' => 'Confirme a autorização expressa do paciente para incluir o CID.',
            'cid_authorization.accepted' => 'Confirme a autorização expressa do paciente para incluir o CID.',
            'cid_authorization.accepted_if' => 'Confirme a autorização expressa do paciente para incluir o CID.',
        ];
    }
}
