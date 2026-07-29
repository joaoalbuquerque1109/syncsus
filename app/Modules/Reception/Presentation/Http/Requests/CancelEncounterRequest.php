<?php

declare(strict_types=1);

namespace App\Modules\Reception\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canAny(['encounters.cancel', 'encounters.cancel_clinical']) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'confirmation' => ['accepted'],
        ];
    }
}
