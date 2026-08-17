<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelLaboratoryOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('laboratory.orders.cancel') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['accepted'],
        ];
    }
}
