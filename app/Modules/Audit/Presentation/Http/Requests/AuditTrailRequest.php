<?php

declare(strict_types=1);

namespace App\Modules\Audit\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AuditTrailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('audit.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'action' => ['nullable', 'string', 'max:100'],
            'access_type' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer', 'exists:core.users,id'],
            'patient' => ['nullable', 'string', 'max:64'],
            'encounter' => ['nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->date('date_from') || ! $this->date('date_to')) {
                    return;
                }
                if ($this->date('date_from')->diffInDays($this->date('date_to')) > 366) {
                    $validator->errors()->add('date_to', 'O período máximo permitido é de 366 dias.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->input('date_from', today()->subDays(7)->toDateString()),
            'date_to' => $this->input('date_to', today()->toDateString()),
        ]);
    }
}
