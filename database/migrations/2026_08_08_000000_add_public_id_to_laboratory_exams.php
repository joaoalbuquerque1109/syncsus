<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_exams', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable()->after('id');
        });

        DB::table('laboratory_exams')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($exams): void {
                foreach ($exams as $exam) {
                    DB::table('laboratory_exams')
                        ->where('id', $exam->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });

        Schema::table('laboratory_exams', function (Blueprint $table): void {
            $table->ulid('public_id')->nullable(false)->change();
            $table->unique('public_id');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_exams', function (Blueprint $table): void {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
