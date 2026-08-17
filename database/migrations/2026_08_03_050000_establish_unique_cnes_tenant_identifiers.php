<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('organizations')->orderBy('id')->each(function (object $organization): void {
            $organizationCnes = $this->normalize($organization->cnes_code);
            $unitCnes = DB::table('health_units')
                ->where('organization_id', $organization->id)
                ->whereNotNull('cnes_code')
                ->orderBy('id')
                ->pluck('cnes_code')
                ->map(fn (mixed $value): ?string => $this->normalize($value))
                ->first(fn (?string $value): bool => $value !== null);
            $canonical = $organizationCnes ?? $unitCnes;

            DB::table('organizations')->where('id', $organization->id)->update([
                'cnes_code' => $canonical,
            ]);
            if ($canonical !== null) {
                DB::table('health_units')
                    ->where('organization_id', $organization->id)
                    ->whereNull('cnes_code')
                    ->update(['cnes_code' => $canonical]);
            }
        });

        DB::table('health_units')->whereNotNull('cnes_code')->orderBy('id')->each(function (object $unit): void {
            DB::table('health_units')->where('id', $unit->id)->update([
                'cnes_code' => $this->normalize($unit->cnes_code),
            ]);
        });

        $this->ensureNoDuplicates('organizations');
        $this->ensureNoDuplicates('health_units');

        Schema::table('organizations', function (Blueprint $table): void {
            $table->unique('cnes_code', 'organizations_cnes_code_unique');
        });
        Schema::table('health_units', function (Blueprint $table): void {
            $table->unique('cnes_code', 'health_units_cnes_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('health_units', function (Blueprint $table): void {
            $table->dropUnique('health_units_cnes_code_unique');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique('organizations_cnes_code_unique');
        });
    }

    private function normalize(mixed $value): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $value);

        return is_string($digits) && strlen($digits) === 7 ? $digits : null;
    }

    private function ensureNoDuplicates(string $table): void
    {
        $duplicates = DB::table($table)
            ->select('cnes_code')
            ->whereNotNull('cnes_code')
            ->groupBy('cnes_code')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('cnes_code');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'CNES duplicado em '.$table.': '.$duplicates->implode(', ').'. Corrija os dados antes de continuar.',
            );
        }
    }
};
