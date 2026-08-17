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
        Schema::create('public_lookup_index', function (Blueprint $table): void {
            $table->id();
            $table->string('public_id_or_code', 64)->unique();
            $table->string('entity_type', 48)->index();
            $table->string('tenant_connection', 128);
            $table->timestamps();
        });

        $connection = (string) config('database.default');
        $now = now();

        DB::table('panels')->orderBy('id')->chunkById(500, function ($panels) use ($connection, $now): void {
            DB::table('public_lookup_index')->insertOrIgnore(
                $panels->map(fn (object $panel): array => [
                    'public_id_or_code' => (string) $panel->public_code,
                    'entity_type' => 'panel',
                    'tenant_connection' => $connection,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });

        DB::table('documents')->orderBy('id')->chunkById(500, function ($documents) use ($connection, $now): void {
            DB::table('public_lookup_index')->insertOrIgnore(
                $documents->map(fn (object $document): array => [
                    'public_id_or_code' => (string) $document->verification_code,
                    'entity_type' => 'clinical_document',
                    'tenant_connection' => $connection,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_lookup_index');
    }
};
