<?php

declare(strict_types=1);

namespace App\Modules\Medical\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMedicalAddendumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('medical.complete') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'content' => ['required', 'string', 'min:10', 'max:12000'],
        ];
    }
}
