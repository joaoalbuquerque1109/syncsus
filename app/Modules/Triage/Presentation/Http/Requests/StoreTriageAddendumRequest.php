<?php

declare(strict_types=1);

namespace App\Modules\Triage\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreTriageAddendumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('triage.addendum') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:255'],
            'content' => ['required', 'string', 'min:10', 'max:8000'],
        ];
    }
}
