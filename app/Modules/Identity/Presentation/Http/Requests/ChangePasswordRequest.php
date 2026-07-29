<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'current_password:web'],
            'password' => [
                'required',
                'confirmed',
                'different:current_password',
                Password::defaults(),
            ],
        ];
    }
}
