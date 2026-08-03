<?php

declare(strict_types=1);

namespace App\Modules\Administration\Presentation\Http\Requests;

use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class ProvisionTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->isPlatformAdministrator();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'cnes_code' => [
                'required', 'digits:7',
                Rule::unique('organizations', 'cnes_code'),
                Rule::unique('health_units', 'cnes_code'),
            ],
            'legal_name' => ['required', 'string', 'min:3', 'max:255'],
            'trade_name' => ['required', 'string', 'min:3', 'max:255'],
            'document_number' => ['nullable', 'string', 'max:32'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'street_number' => ['nullable', 'string', 'max:32'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'manager_name' => ['required', 'string', 'min:3', 'max:255'],
            'manager_email' => ['required', 'email', 'max:255'],
            'manager_password' => [
                'required', 'confirmed', 'max:255',
                Password::min(12)->mixedCase()->letters()->numbers()->symbols(),
            ],
        ];
    }
}
