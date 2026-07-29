<?php

declare(strict_types=1);

namespace App\Modules\Documents\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VoidClinicalDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.issue_documents') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:10', 'max:1000']];
    }
}
