<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Administration\Infrastructure\Eloquent\HealthUnit;
use App\Modules\Triage\Infrastructure\Eloquent\TriageDiscriminator;
use App\Modules\Triage\Infrastructure\Eloquent\TriageFlowchart;
use App\Modules\Triage\Infrastructure\Eloquent\TriageProtocol;
use App\Modules\Triage\Infrastructure\Eloquent\VitalSignRange;
use App\Support\Tenancy\TenantConnectionManager;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

final class TriageCatalogSeeder extends Seeder
{
    public function run(): void
    {
        foreach (HealthUnit::query()->get() as $unit) {
            $context = app(TenantContext::class);
            $context->reset();
            $context->resolve($unit, app(TenantConnectionManager::class)->connectionName($unit));
            $this->seedUnit();
        }
    }

    private function seedUnit(): void
    {
        $protocol = TriageProtocol::query()->updateOrCreate(
            ['code' => 'SYNC-TRIAGE', 'version' => '2026.1'],
            ['name' => 'Protocolo institucional de classificação', 'is_active' => true],
        );

        foreach ([
            ['code' => 'GENERAL', 'name' => 'Mal-estar geral', 'display_order' => 10],
            ['code' => 'PAIN', 'name' => 'Dor', 'display_order' => 20],
            ['code' => 'TRAUMA', 'name' => 'Trauma', 'display_order' => 30],
            ['code' => 'RESPIRATORY', 'name' => 'Queixa respiratória', 'display_order' => 40],
            ['code' => 'PEDIATRIC', 'name' => 'Avaliação pediátrica', 'display_order' => 50],
        ] as $flowData) {
            $flowchart = TriageFlowchart::query()->updateOrCreate(
                ['triage_protocol_id' => $protocol->getKey(), 'code' => $flowData['code']],
                [...$flowData, 'is_active' => true],
            );
            foreach ([
                ['code' => 'NO-ALARM', 'name' => 'Sem sinal de alarme', 'display_order' => 10],
                ['code' => 'MODERATE', 'name' => 'Sinais moderados', 'display_order' => 20],
                ['code' => 'SEVERE', 'name' => 'Sinais importantes', 'display_order' => 30],
            ] as $discriminator) {
                TriageDiscriminator::query()->updateOrCreate(
                    ['triage_flowchart_id' => $flowchart->getKey(), 'code' => $discriminator['code']],
                    [...$discriminator, 'guidance' => 'Catálogo de apoio. A classificação deve ser confirmada pelo profissional.', 'is_active' => true],
                );
            }
        }

        foreach ($this->ranges() as $range) {
            VitalSignRange::query()->updateOrCreate(
                ['metric' => $range['metric']],
                [...$range, 'configuration_version' => '2026.1', 'is_active' => true],
            );
        }
    }

    /** @return list<array{metric: string, label: string, unit: string, hard_min: float|int, hard_max: float|int, warning_min: float|int, warning_max: float|int}> */
    private function ranges(): array
    {
        return [
            ['metric' => 'systolic_bp', 'label' => 'Pressão sistólica', 'unit' => 'mmHg', 'hard_min' => 20, 'hard_max' => 350, 'warning_min' => 50, 'warning_max' => 250],
            ['metric' => 'diastolic_bp', 'label' => 'Pressão diastólica', 'unit' => 'mmHg', 'hard_min' => 10, 'hard_max' => 250, 'warning_min' => 30, 'warning_max' => 160],
            ['metric' => 'heart_rate', 'label' => 'Frequência cardíaca', 'unit' => 'bpm', 'hard_min' => 10, 'hard_max' => 300, 'warning_min' => 30, 'warning_max' => 220],
            ['metric' => 'respiratory_rate', 'label' => 'Frequência respiratória', 'unit' => 'irpm', 'hard_min' => 1, 'hard_max' => 100, 'warning_min' => 6, 'warning_max' => 60],
            ['metric' => 'temperature_c', 'label' => 'Temperatura', 'unit' => '°C', 'hard_min' => 20, 'hard_max' => 50, 'warning_min' => 32, 'warning_max' => 43],
            ['metric' => 'oxygen_saturation', 'label' => 'Saturação de oxigênio', 'unit' => '%', 'hard_min' => 1, 'hard_max' => 100, 'warning_min' => 50, 'warning_max' => 100],
            ['metric' => 'blood_glucose', 'label' => 'Glicemia', 'unit' => 'mg/dL', 'hard_min' => 1, 'hard_max' => 1500, 'warning_min' => 20, 'warning_max' => 800],
            ['metric' => 'weight_kg', 'label' => 'Peso', 'unit' => 'kg', 'hard_min' => 0.2, 'hard_max' => 500, 'warning_min' => 0.5, 'warning_max' => 350],
            ['metric' => 'height_cm', 'label' => 'Altura', 'unit' => 'cm', 'hard_min' => 20, 'hard_max' => 280, 'warning_min' => 30, 'warning_max' => 250],
            ['metric' => 'pain_scale', 'label' => 'Escala de dor', 'unit' => '0–10', 'hard_min' => 0, 'hard_max' => 10, 'warning_min' => 0, 'warning_max' => 10],
            ['metric' => 'glasgow_score', 'label' => 'Escala de Glasgow', 'unit' => '3–15', 'hard_min' => 3, 'hard_max' => 15, 'warning_min' => 3, 'warning_max' => 15],
            ['metric' => 'circumference_cm', 'label' => 'Circunferência', 'unit' => 'cm', 'hard_min' => 1, 'hard_max' => 300, 'warning_min' => 5, 'warning_max' => 250],
        ];
    }
}
