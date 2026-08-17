<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveExamGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('administration.manage') === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unit = $this->attributes->get('active_health_unit');
        $organizationId = $unit instanceof HealthUnit ? $unit->organization_id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.exam_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('core.exams', 'id')->where('organization_id', $organizationId),
            ],
            'is_active' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'Adicione pelo menos um exame ao grupo.',
            'items.min' => 'Adicione pelo menos um exame ao grupo.',
            'items.*.exam_id.distinct' => 'O mesmo exame não pode ser adicionado mais de uma vez.',
            'items.*.exam_id.exists' => 'Um dos exames não pertence à organização ativa.',
        ];
    }
}
