<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ValidCpf implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cpf = preg_replace('/\D+/', '', (string) $value);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            $fail('O CPF informado é inválido.');

            return;
        }

        for ($digit = 9; $digit < 11; $digit++) {
            $sum = 0;
            for ($index = 0; $index < $digit; $index++) {
                $sum += ((int) $cpf[$index]) * (($digit + 1) - $index);
            }
            $check = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$digit] !== $check) {
                $fail('O CPF informado é inválido.');

                return;
            }
        }
    }
}
