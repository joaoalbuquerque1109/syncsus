<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SwitchHealthUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['health_unit' => ['required', 'ulid']];
    }
}
