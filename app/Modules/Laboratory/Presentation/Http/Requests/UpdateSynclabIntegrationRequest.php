<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSynclabIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_url' => ['required', 'url:https', 'max:255'],
            'cnes_code' => ['required', 'digits:7'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'transmission_enabled' => ['nullable', 'boolean'],
        ];
    }
}
