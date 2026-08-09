<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class SusProcedureCatalogSeeder extends Seeder
{
    private const MINIMUM_EXPECTED_ROWS = 4000;

    public function run(): void
    {
        $path = database_path('data/sus_procedures/procedures.csv');
        $now = now();
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível ler o catálogo de procedimentos SUS.');
        }

        try {
            $headers = fgetcsv($handle, 0, ',', '"', '');
            if ($headers !== [
                'code',
                'complexity',
                'sex_restriction',
                'minimum_age_months',
                'maximum_age_months',
                'description',
            ]) {
                throw new RuntimeException('Cabeçalho inválido no catálogo de procedimentos SUS.');
            }

            $catalog = [];
            while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($row) !== 6) {
                    throw new RuntimeException('Linha inválida no catálogo de procedimentos SUS.');
                }

                $code = trim((string) $row[0]);
                $description = trim((string) $row[5]);
                if (! preg_match('/^\d{10}$/', $code) || $description === '') {
                    throw new RuntimeException("Procedimento SUS inválido: {$code}.");
                }
                $catalog[$code] = [
                    'code' => $code,
                    'description' => $description,
                    'complexity' => $this->nullable($row[1]),
                    'sex_restriction' => $this->nullable($row[2]),
                    'minimum_age_months' => $this->age($row[3], $code),
                    'maximum_age_months' => $this->age($row[4], $code),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        } finally {
            fclose($handle);
        }

        if (count($catalog) < self::MINIMUM_EXPECTED_ROWS) {
            throw new RuntimeException('O catálogo de procedimentos SUS parece estar truncado.');
        }

        DB::transaction(function () use ($catalog, $now): void {
            DB::table('sus_procedures')->update(['is_active' => false, 'updated_at' => $now]);
            foreach (array_chunk(array_values($catalog), 500) as $chunk) {
                DB::table('sus_procedures')->upsert(
                    $chunk,
                    ['code'],
                    [
                        'description',
                        'complexity',
                        'sex_restriction',
                        'minimum_age_months',
                        'maximum_age_months',
                        'is_active',
                        'updated_at',
                    ],
                );
            }
        });
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function age(mixed $value, string $code): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || $value === '9999') {
            return null;
        }
        if (! ctype_digit($value)) {
            throw new RuntimeException("Idade inválida no procedimento SUS {$code}.");
        }

        return (int) $value;
    }
}
