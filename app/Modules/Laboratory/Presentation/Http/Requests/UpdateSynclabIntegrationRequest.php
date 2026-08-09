<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSynclabIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unit = $this->attributes->get('active_health_unit');
        $unitId = $unit instanceof HealthUnit ? $unit->getKey() : null;
        $organizationId = $unit instanceof HealthUnit ? $unit->organization_id : null;

        return [
            'base_url' => ['required', 'url:https', 'max:255'],
            'cnes_code' => [
                'required',
                'digits:7',
                Rule::unique('organizations', 'cnes_code')->ignore($organizationId),
                Rule::unique('health_units', 'cnes_code')->ignore($unitId),
            ],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:500'],
            'transmission_enabled' => ['nullable', 'boolean'],
            'result_sync_enabled' => ['nullable', 'boolean'],
        ];
    }
}
