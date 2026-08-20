<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Administration\Infrastructure\Eloquent\Organization;
use App\Rules\ValidCpf;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

final class EmployeeRegistrationRequest extends FormRequest
{
    /** @var list<string> */
    public const CLINICAL_ROLES = ['doctor', 'triage_professional'];

    /** @var list<string> */
    public const ALLOWED_ROLES = ['receptionist', 'triage_professional', 'doctor', 'auditor'];

    private ?Organization $resolvedOrganization = null;

    private bool $organizationResolutionAttempted = false;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $organizationId = $this->resolvedOrganization()?->getKey() ?? 0;
        $isClinical = in_array($this->input('role'), self::CLINICAL_ROLES, true);

        return [
            'cnes_code' => ['required', 'string'],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'cpf' => [
                'required', new ValidCpf,
                Rule::unique('core.users', 'cpf')->where('organization_id', $organizationId),
            ],
            'birth_date' => ['required', 'date', 'before:today'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('core.users', 'email')->where('organization_id', $organizationId),
            ],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(self::ALLOWED_ROLES), Rule::exists('core.roles', 'name')],
            'council_type' => [Rule::requiredIf($isClinical), 'nullable', 'string', 'max:16'],
            'registration_number' => [Rule::requiredIf($isClinical), 'nullable', 'string', 'max:32'],
            'registration_state' => [Rule::requiredIf($isClinical), 'nullable', 'string', 'size:2'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf' => NormalizesBrazilianData::digits($this->input('cpf')),
            'cnes_code' => NormalizesBrazilianData::digits($this->input('cnes_code')),
        ]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (filled($this->input('cnes_code')) && $this->resolvedHealthUnit() === null) {
                $validator->errors()->add('cnes_code', 'CNES não encontrado ou unidade inativa.');
            }
        });
    }

    public function resolvedOrganization(): ?Organization
    {
        if (! $this->organizationResolutionAttempted) {
            $this->organizationResolutionAttempted = true;
            $cnes = NormalizesBrazilianData::digits((string) $this->input('cnes_code'));
            $this->resolvedOrganization = filled($cnes)
                ? Organization::query()->where('is_active', true)->where('cnes_code', $cnes)->first()
                : null;
        }

        return $this->resolvedOrganization;
    }

    public function resolvedHealthUnit(): ?HealthUnit
    {
        $organization = $this->resolvedOrganization();

        return $organization === null ? null : HealthUnit::query()
            ->where('organization_id', $organization->getKey())
            ->where('is_active', true)
            ->first();
    }
}
