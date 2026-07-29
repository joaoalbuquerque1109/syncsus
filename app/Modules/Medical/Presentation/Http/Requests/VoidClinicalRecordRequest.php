<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class VoidClinicalRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmation' => ['accepted'],
        ];
    }
}
