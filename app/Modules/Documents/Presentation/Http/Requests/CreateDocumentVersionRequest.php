<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateDocumentVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.issue_documents') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'body' => ['required', 'string', 'min:10', 'max:16000'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['nullable', 'date'],
            'duration_value' => ['nullable', 'integer', 'min:1', 'max:365'],
            'duration_unit' => ['nullable', 'required_with:duration_value', Rule::in(['hours', 'days'])],
            'include_cid' => ['nullable', 'boolean'],
            'cid_text' => ['nullable', 'required_if:include_cid,1', 'string', 'max:255'],
            'cid_authorization' => ['nullable', 'accepted_if:include_cid,1'],
            'additional_information' => ['nullable', 'string', 'max:4000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['include_cid' => $this->boolean('include_cid')]);
    }
}
