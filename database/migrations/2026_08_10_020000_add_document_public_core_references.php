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
        Schema::table('documents', function (Blueprint $table): void {
            $table->ulid('patient_public_id')->nullable()->after('patient_id')->index();
        });
        Schema::table('medical_certificates', function (Blueprint $table): void {
            $table->ulid('patient_public_id')->nullable()->after('patient_id')->index();
        });

        DB::table('documents')->orderBy('id')->chunkById(500, function ($documents): void {
            $publicIds = DB::table('patients')
                ->whereIn('id', $documents->pluck('patient_id')->all())
                ->pluck('public_id', 'id');
            foreach ($documents as $document) {
                DB::table('documents')->where('id', $document->id)->update([
                    'patient_public_id' => $publicIds[$document->patient_id] ?? null,
                ]);
            }
        });
        DB::table('medical_certificates')->orderBy('id')->chunkById(500, function ($certificates): void {
            $publicIds = DB::table('patients')
                ->whereIn('id', $certificates->pluck('patient_id')->all())
                ->pluck('public_id', 'id');
            foreach ($certificates as $certificate) {
                DB::table('medical_certificates')->where('id', $certificate->id)->update([
                    'patient_public_id' => $publicIds[$certificate->patient_id] ?? null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('medical_certificates', fn (Blueprint $table) => $table->dropColumn('patient_public_id'));
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('patient_public_id'));
    }
};
