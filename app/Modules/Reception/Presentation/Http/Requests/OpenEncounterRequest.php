<?php

declare(strict_types=1);

namespace App\Modules\Reception\Presentation\Http\Requests;

use App\Modules\Administration\Infrastructure\Eloquent\ArrivalMethod;
use App\Modules\Administration\Infrastructure\Eloquent\EntryType;
use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Patients\Infrastructure\Eloquent\Patient;
use App\Modules\Queues\Infrastructure\Eloquent\Queue;
use App\Rules\ValidCpf;
use App\Support\Text\NormalizesBrazilianData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class OpenEncounterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('encounters.open') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unit = $this->attributes->get('active_health_unit');
        $unitId = $unit instanceof HealthUnit ? $unit->getKey() : 0;
        $organizationId = $unit instanceof HealthUnit ? $unit->organization_id : 0;

        return [
            'idempotency_key' => ['required', 'string', 'max:64'],
            'patient_public_id' => ['required', 'exists:patients,public_id'],
            'entry_type_id' => [
                'required', 'integer',
                Rule::exists('entry_types', 'id')
                    ->where('organization_id', $organizationId)->where('is_active', true),
            ],
            'arrival_method_id' => [
                'required', 'integer',
                Rule::exists('arrival_methods', 'id')
                    ->where('organization_id', $organizationId)->where('is_active', true),
            ],
            'arrival_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(10)->format('Y-m-d H:i:s')],
            'origin' => ['nullable', 'string', 'max:255'],
            'entry_reason' => ['required', 'string', 'min:3', 'max:255'],
            'reception_notes' => ['nullable', 'string', 'max:2000'],
            'vehicle_information' => ['nullable', 'string', 'max:255'],
            'administrative_priority' => ['required', Rule::in(['none', 'elderly', 'pregnant', 'disabled', 'child_with_infant', 'other'])],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('health_unit_id', $unitId)->where('is_active', true)],
            'specialty_id' => [
                'nullable', 'integer',
                Rule::exists('specialties', 'id')
                    ->where('organization_id', $organizationId)->where('is_active', true),
            ],
            'queue_id' => ['nullable', 'integer', Rule::exists('queues', 'id')->where('health_unit_id', $unitId)->where('is_active', true)],
            'companion_name' => ['nullable', 'string', 'max:255'],
            'companion_cpf' => ['nullable', new ValidCpf],
            'companion_phone' => ['nullable', 'string', 'max:32'],
            'companion_relationship' => ['nullable', 'string', 'max:64'],
            'companion_is_guardian' => ['nullable', 'boolean'],
            'request_exams' => ['nullable', 'boolean'],
            'exam_requester_id' => ['nullable', 'required_if:request_exams,1', 'integer', 'exists:users,id'],
            'exam_priority' => ['nullable', 'required_if:request_exams,1', Rule::in(['routine', 'urgent', 'emergency'])],
            'exam_clinical_indication' => ['nullable', 'required_if:request_exams,1', 'string', 'min:5', 'max:4000'],
            'exam_notes' => ['nullable', 'string', 'max:4000'],
            'exam_ids' => ['nullable', 'required_if:request_exams,1', 'array', 'min:1', 'max:30'],
            'exam_ids.*' => ['required', 'integer', 'distinct', 'exists:laboratory_exams,id'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $this->validateEntryTypeRules($validator);
                $unit = $this->attributes->get('active_health_unit');
                $arrivalMethod = ArrivalMethod::query()
                    ->when(
                        $unit instanceof HealthUnit,
                        fn ($query) => $query->where('organization_id', $unit->organization_id),
                    )
                    ->find($this->integer('arrival_method_id'));
                if ($arrivalMethod?->requires_vehicle_data && blank($this->input('vehicle_information'))) {
                    $validator->errors()->add('vehicle_information', 'Informe os dados do veículo para esta forma de chegada.');
                }
            },
        ];
    }

    private function validateEntryTypeRules(Validator $validator): void
    {
        $unit = $this->attributes->get('active_health_unit');
        if (! $unit instanceof HealthUnit) {
            return;
        }
        $entryType = EntryType::query()
            ->where('organization_id', $unit->organization_id)
            ->whereKey($this->integer('entry_type_id'))->where('is_active', true)->first();
        $patient = Patient::query()->where('public_id', $this->input('patient_public_id'))->first();
        if ($entryType === null || $patient === null) {
            return;
        }
        if ((int) $patient->organization_id !== (int) $unit->organization_id) {
            $validator->errors()->add('patient_public_id', 'Paciente indisponivel para esta organizacao.');
        }
        if ($patient->is_provisional && ! $entryType->allows_provisional_patient) {
            $validator->errors()->add('patient_public_id', 'Este tipo de entrada exige identificacao definitiva.');
        }

        $queueId = $this->integer('queue_id') ?: (int) $entryType->default_queue_id;
        $queue = Queue::query()->with('department')
            ->whereKey($queueId)
            ->where('health_unit_id', $unit->getKey())
            ->where('is_active', true)
            ->first();
        if ($queue === null) {
            $validator->errors()->add('queue_id', 'Selecione uma fila valida desta unidade.');

            return;
        }
        $expectedDepartmentType = $entryType->requires_triage ? 'triage' : 'medical';
        if ((string) $queue->department->type !== $expectedDepartmentType) {
            $validator->errors()->add(
                'queue_id',
                $entryType->requires_triage
                    ? 'Este tipo de entrada deve iniciar em uma fila de triagem.'
                    : 'Este tipo de entrada dispensa triagem e deve iniciar em uma fila medica.',
            );
        }
        if ($this->filled('department_id') && (int) $queue->department_id !== $this->integer('department_id')) {
            $validator->errors()->add('department_id', 'O setor nao corresponde a fila selecionada.');
        }
        if ($this->filled('specialty_id') && $queue->specialty_id !== null
            && (int) $queue->specialty_id !== $this->integer('specialty_id')) {
            $validator->errors()->add('specialty_id', 'A especialidade nao corresponde a fila selecionada.');
        }
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'companion_cpf' => NormalizesBrazilianData::digits($this->input('companion_cpf')),
            'companion_is_guardian' => $this->boolean('companion_is_guardian'),
            'request_exams' => $this->boolean('request_exams'),
        ]);
    }
}
