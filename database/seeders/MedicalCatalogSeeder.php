<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MedicalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $catalog = [];

        foreach (glob(database_path('data/cid10/*.csv')) ?: [] as $file) {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Não foi possível ler o catálogo CID: {$file}");
            }

            try {
                fgetcsv($handle, 0, ',', '"', '');

                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    if (count($row) < 2 || blank($row[0]) || blank($row[1])) {
                        continue;
                    }

                    $catalog[] = [
                        'code' => trim((string) $row[0]),
                        'description' => trim((string) $row[1]),
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            } finally {
                fclose($handle);
            }
        }

        if (count($catalog) !== 1835) {
            throw new RuntimeException('O catálogo CID-10 deve conter os 1.835 registros da fonte fornecida.');
        }

        foreach (array_chunk($catalog, 500) as $chunk) {
            DB::table('diagnosis_codes')->upsert(
                $chunk,
                ['code'],
                ['description', 'is_active', 'updated_at'],
            );
        }

        // Mantém códigos detalhados já utilizados pelo sistema, além das categorias da planilha.
        foreach ([
            ['code' => 'R52', 'description' => 'Dor não classificada em outra parte'],
            ['code' => 'R50.9', 'description' => 'Febre não especificada'],
            ['code' => 'R06.0', 'description' => 'Dispneia'],
            ['code' => 'R10.4', 'description' => 'Outras dores abdominais e as não especificadas'],
            ['code' => 'S80.0', 'description' => 'Contusão do joelho'],
            ['code' => 'J06.9', 'description' => 'Infecção aguda das vias aéreas superiores não especificada'],
            ['code' => 'I10', 'description' => 'Hipertensão essencial'],
            ['code' => 'Z00.0', 'description' => 'Exame médico geral'],
        ] as $item) {
            DiagnosisCode::query()->updateOrCreate(
                ['code' => $item['code']],
                [...$item, 'is_active' => true],
            );
        }
    }
}
