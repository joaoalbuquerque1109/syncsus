<?php

declare(strict_types=1);

namespace App\Modules\Triage\Application\Services;

use App\Modules\Triage\Infrastructure\Eloquent\VitalSignRange;
use Illuminate\Validation\ValidationException;

final class VitalSignTechnicalValidator
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{values: array<string, float|int|null>, alerts: list<string>, version: string}
     */
    public function validate(array $data, bool $confirmed): array
    {
        $values = $this->normalize($data);
        $ranges = VitalSignRange::query()->where('is_active', true)->get()->keyBy('metric');
        $alerts = [];
        $errors = [];
        $version = 'unconfigured';

        foreach ($values as $metric => $value) {
            if ($value === null) {
                continue;
            }
            $range = $ranges->get($metric);
            if (! $range instanceof VitalSignRange) {
                continue;
            }
            $version = (string) $range->configuration_version;
            $hardMin = $range->hard_min === null ? null : (float) $range->hard_min;
            $hardMax = $range->hard_max === null ? null : (float) $range->hard_max;
            if (($hardMin !== null && $value < $hardMin) || ($hardMax !== null && $value > $hardMax)) {
                $errors[$metric] = "O valor de {$range->label} está fora do limite técnico permitido ({$hardMin}–{$hardMax} {$range->unit}).";

                continue;
            }
            $warningMin = $range->warning_min === null ? null : (float) $range->warning_min;
            $warningMax = $range->warning_max === null ? null : (float) $range->warning_max;
            if (($warningMin !== null && $value < $warningMin) || ($warningMax !== null && $value > $warningMax)) {
                $alerts[] = $metric;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($alerts !== [] && ! $confirmed) {
            throw ValidationException::withMessages([
                'confirm_outside_ranges' => 'Há valores fora da faixa técnica usual. Confira a digitação e confirme explicitamente para salvar.',
            ]);
        }

        return ['values' => $values, 'alerts' => $alerts, 'version' => $version];
    }

    /** @param array<string, mixed> $data
     * @return array<string, float|int|null>
     */
    private function normalize(array $data): array
    {
        $height = $this->number($data['height'] ?? null);
        if ($height !== null && ($data['height_unit'] ?? 'cm') === 'm') {
            $height *= 100;
        }

        return [
            'systolic_bp' => $this->integer($data['systolic_bp'] ?? null),
            'diastolic_bp' => $this->integer($data['diastolic_bp'] ?? null),
            'heart_rate' => $this->integer($data['heart_rate'] ?? null),
            'respiratory_rate' => $this->integer($data['respiratory_rate'] ?? null),
            'temperature_c' => $this->number($data['temperature_c'] ?? null),
            'oxygen_saturation' => $this->number($data['oxygen_saturation'] ?? null),
            'blood_glucose' => $this->integer($data['blood_glucose'] ?? null),
            'weight_kg' => $this->number($data['weight_kg'] ?? null),
            'height_cm' => $height,
            'pain_scale' => $this->integer($data['pain_scale'] ?? null),
            'glasgow_score' => $this->integer($data['glasgow_score'] ?? null),
            'circumference_cm' => $this->number($data['circumference_cm'] ?? null),
        ];
    }

    private function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) str_replace(',', '.', (string) $value);
    }

    private function integer(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
