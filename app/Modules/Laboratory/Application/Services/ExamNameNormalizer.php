<?php

declare(strict_types=1);

namespace App\Modules\Laboratory\Application\Services;

use Illuminate\Support\Str;

final class ExamNameNormalizer
{
    public function normalize(string $name): string
    {
        $ascii = mb_strtoupper(Str::ascii($name));
        $words = preg_split('/[^A-Z0-9]+/', $ascii, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map($this->singularize(...), $words));
    }

    public function similarity(string $first, string $second): float
    {
        $first = $this->normalize($first);
        $second = $this->normalize($second);
        if ($first === '' || $second === '') {
            return 0.0;
        }

        similar_text($first, $second, $percentage);

        return round($percentage / 100, 4);
    }

    private function singularize(string $word): string
    {
        if (strlen($word) > 4 && str_ends_with($word, 'ES')) {
            $radical = substr($word, 0, -2);
            if (preg_match('/[RSZ]$/', $radical) === 1) {
                return $radical;
            }

            return substr($word, 0, -1);
        }

        // Irregular -NS plurals such as ITEM/ITENS and HOMEM/HOMENS remain out of scope.
        if (strlen($word) > 3 && str_ends_with($word, 'S') && ! str_ends_with($word, 'SS')) {
            return substr($word, 0, -1);
        }

        return $word;
    }
}
