<?php

declare(strict_types=1);

namespace App\Modules\Professionals\Presentation\Http\Requests;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Professionals\Infrastructure\Eloquent\HealthProfessional;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Rules\ValidCpf;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveHealthProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $professional = $this->route('professional');
        $professionalId = $professional instanceof HealthProfessional ? $professional->getKey() : null;
        $unit = $this->attributes->get('active_health_unit');
        $organizationId = $unit instanceof HealthUnit ? $unit->organization_id : 0;
        $professionType = (string) $this->input('profession_type');
        $requiresOperationalAssignment = filled($this->input('user_id'))
            && in_array($professionType, ['doctor', 'nurse', 'technician'], true);
        $unitIds = $this->integerList('health_unit_ids');
        $specialtyIds = $this->integerList('specialty_ids');
        $allowedQueues = Queue::query()
            ->whereHas('healthUnit', fn ($query) => $query->where('organization_id', $organizationId))
            ->whereIn('health_unit_id', $unitIds)
            ->where('is_active', true);
        if ($professionType === 'doctor') {
            $allowedQueues->whereHas('department', fn ($query) => $query->where('type', 'medical'))
                ->whereIn('specialty_id', $specialtyIds);
        } elseif (in_array($professionType, ['nurse', 'technician'], true)) {
            $allowedQueues->whereHas('department', fn ($query) => $query->where('type', 'triage'));
        } else {
            $allowedQueues->whereRaw('1 = 0');
        }
        $allowedQueueIds = $allowedQueues->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $selectedQueueIds = $this->integerList('queue_ids');
        $allowedServicePointIds = Queue::query()
            ->whereIn('id', $selectedQueueIds)
            ->with('servicePoints:id,is_active')
            ->get()
            ->flatMap->servicePoints
            ->where('is_active', true)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        return [
            'user_id' => [
                'nullable', 'integer',
                Rule::exists('users', 'id')->where('organization_id', $organizationId),
                Rule::unique('health_professionals', 'user_id')->ignore($professionalId),
            ],
            'institutional_code' => [
                'required', 'string', 'max:32',
                Rule::unique('health_professionals', 'institutional_code')
                    ->where('organization_id', $organizationId)
                    ->ignore($professionalId),
            ],
            'profession_type' => ['required', Rule::in(['doctor', 'nurse', 'technician', 'physiotherapist', 'psychologist', 'social_worker', 'other'])],
            'treatment_name' => ['nullable', 'string', 'max:32'],
            'full_name' => ['required', 'string', 'min:3', 'max:255'],
            'social_name' => ['nullable', 'string', 'max:255'],
            'cpf' => [
                'nullable', new ValidCpf,
                Rule::unique('health_professionals', 'cpf')
                    ->where('organization_id', $organizationId)
                    ->ignore($professionalId),
            ],
            'birth_date' => ['nullable', 'date', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::in(['female', 'male', 'intersex', 'unknown'])],
            'identity_number' => ['nullable', 'string', 'max:32'],
            'identity_issuer' => ['nullable', 'string', 'max:32'],
            'identity_state' => ['nullable', 'string', 'size:2'],
            'cnes_code' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:32'],
            'mobile' => ['nullable', 'string', 'max:32'],
            'secondary_phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'secondary_email' => ['nullable', 'email', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'state' => ['nullable', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:255'],
            'city_ibge_code' => ['nullable', 'digits_between:6,7'],
            'district' => ['nullable', 'string', 'max:255'],
            'street' => ['nullable', 'string', 'max:255'],
            'street_number' => ['nullable', 'string', 'max:32'],
            'address_complement' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['nullable', 'boolean'],
            'health_unit_ids' => ['required', 'array', 'min:1'],
            'health_unit_ids.*' => [
                'integer', 'distinct',
                Rule::exists('health_units', 'id')->where('organization_id', $organizationId),
            ],
            'specialty_ids' => ['nullable', 'array'],
            'specialty_ids.*' => [
                'integer', 'distinct',
                Rule::exists('specialties', 'id')->where('organization_id', $organizationId),
            ],
            'primary_specialty_id' => [
                'nullable', 'integer',
                Rule::exists('specialties', 'id')->where('organization_id', $organizationId),
            ],
            'specialty_rqe' => ['nullable', 'array'],
            'specialty_rqe.*' => ['nullable', 'string', 'max:32'],
            'specialty_registered_at' => ['nullable', 'array'],
            'specialty_registered_at.*' => ['nullable', 'date', 'before_or_equal:today'],
            'queue_ids' => [Rule::requiredIf($requiresOperationalAssignment), 'array', $requiresOperationalAssignment ? 'min:1' : 'nullable'],
            'queue_ids.*' => ['integer', 'distinct', Rule::in($allowedQueueIds)],
            'service_point_ids' => [Rule::requiredIf($requiresOperationalAssignment), 'array', $requiresOperationalAssignment ? 'min:1' : 'nullable'],
            'service_point_ids.*' => ['integer', 'distinct', Rule::in($allowedServicePointIds)],
            'registrations' => [
                Rule::requiredIf(fn (): bool => in_array($this->input('profession_type'), ['doctor', 'nurse'], true)),
                'array',
            ],
            'registrations.*.council_type' => ['required', 'string', 'max:16'],
            'registrations.*.registration_number' => ['required', 'string', 'max:32'],
            'registrations.*.state' => ['required', 'string', 'size:2'],
            'registrations.*.issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'registrations.*.expires_at' => ['nullable', 'date', 'after_or_equal:today'],
            'registrations.*.is_primary' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $input = $this->input('registrations', []);
        $registrations = collect(is_array($input) ? $input : [])
            ->filter(fn (mixed $registration): bool => is_array($registration) && filled($registration['registration_number'] ?? null))
            ->values()
            ->all();

        $this->merge([
            'cpf' => NormalizesBrazilianData::digits($this->input('cpf')),
            'is_active' => $this->boolean('is_active'),
            'registrations' => $registrations,
        ]);
    }

    /** @return list<int> */
    private function integerList(string $key): array
    {
        $input = $this->input($key, []);

        return collect(is_array($input) ? $input : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->values()
            ->all();
    }
}
