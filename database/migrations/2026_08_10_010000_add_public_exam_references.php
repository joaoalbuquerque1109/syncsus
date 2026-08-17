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
        Schema::table('exam_mappings', function (Blueprint $table): void {
            $table->ulid('exam_public_id')->nullable()->after('exam_id')->index();
        });
        Schema::table('health_unit_exams', function (Blueprint $table): void {
            $table->ulid('exam_public_id')->nullable()->after('exam_id')->index();
        });

        DB::table('exam_mappings')->orderBy('id')->chunkById(500, function ($mappings): void {
            $publicIds = DB::table('exams')
                ->whereIn('id', $mappings->pluck('exam_id')->all())
                ->pluck('public_id', 'id');
            foreach ($mappings as $mapping) {
                DB::table('exam_mappings')->where('id', $mapping->id)->update([
                    'exam_public_id' => $publicIds[$mapping->exam_id] ?? null,
                ]);
            }
        });
        DB::table('health_unit_exams')->orderBy('id')->chunkById(500, function ($availabilities): void {
            $publicIds = DB::table('exams')
                ->whereIn('id', $availabilities->pluck('exam_id')->all())
                ->pluck('public_id', 'id');
            foreach ($availabilities as $availability) {
                DB::table('health_unit_exams')->where('id', $availability->id)->update([
                    'exam_public_id' => $publicIds[$availability->exam_id] ?? null,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('health_unit_exams', fn (Blueprint $table) => $table->dropColumn('exam_public_id'));
        Schema::table('exam_mappings', fn (Blueprint $table) => $table->dropColumn('exam_public_id'));
    }
};
