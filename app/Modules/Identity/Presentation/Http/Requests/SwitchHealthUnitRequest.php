<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class SwitchHealthUnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A troca de unidade em sessao e exclusiva do administrador global -
        // demais usuarios entram numa unidade especifica pela tela de login.
        return $this->user()?->isPlatformAdministrator() ?? false;
    }

    /** @return array<string, list<string|Closure>> */
    public function rules(): array
    {
        return [
            'health_unit' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $cnes = preg_replace('/\D/', '', (string) $value);
                    if (! Str::isUlid((string) $value) && strlen((string) $cnes) !== 7) {
                        $fail('Informe o identificador da unidade ou um CNES válido de 7 dígitos.');
                    }
                },
            ],
        ];
    }
}
