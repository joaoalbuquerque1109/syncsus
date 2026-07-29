<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreExamOrderRequest extends FormRequest
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
            'priority' => ['required', Rule::in(['routine', 'urgent', 'emergency'])],
            'clinical_indication' => ['required', 'string', 'min:5', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.internal_code' => ['nullable', 'string', 'max:32'],
            'items.*.exam_name' => ['required', 'string', 'max:255'],
            'items.*.group' => ['required', Rule::in(['laboratory', 'imaging', 'cardiology', 'other'])],
            'items.*.priority' => ['nullable', Rule::in(['routine', 'urgent', 'emergency'])],
            'items.*.laterality' => ['nullable', Rule::in(['right', 'left', 'bilateral', 'not_applicable'])],
            'items.*.preparation' => ['nullable', 'string', 'max:1000'],
            'items.*.justification' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
