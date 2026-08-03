<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('patients') || ! Schema::hasTable('number_sequences')) {
            return;
        }

        $highest = DB::table('patients')
            ->whereBetween('medical_record_number', ['P00000000', 'P99999999'])
            ->pluck('medical_record_number')
            ->reduce(static function (int $maximum, string $medicalRecordNumber): int {
                if (preg_match('/^P(\d{8})$/', $medicalRecordNumber, $matches) !== 1) {
                    return $maximum;
                }

                return max($maximum, (int) $matches[1]);
            }, 0);

        $sequence = DB::table('number_sequences')
            ->where('scope', 'patient_mrn')
            ->where('date_key', '')
            ->first();

        if ($sequence === null) {
            DB::table('number_sequences')->insert([
                'scope' => 'patient_mrn',
                'date_key' => '',
                'current_value' => $highest,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return;
        }

        if ((int) $sequence->current_value < $highest) {
            DB::table('number_sequences')
                ->where('id', $sequence->id)
                ->update([
                    'current_value' => $highest,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // This repair is intentionally irreversible. Lowering the sequence
        // could reintroduce duplicate medical record numbers.
    }
};
