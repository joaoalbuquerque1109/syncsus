<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Medical\Infrastructure\Eloquent\DiagnosisCode;
use Illuminate\Database\Seeder;

final class MedicalCatalogSeeder extends Seeder
{
    public function run(): void
    {
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
