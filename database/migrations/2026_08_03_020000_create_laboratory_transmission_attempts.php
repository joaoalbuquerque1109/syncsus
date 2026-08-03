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
        Schema::create('laboratory_transmission_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laboratory_order_transmission_id')
                ->constrained('laboratory_order_transmissions')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->string('status', 32)->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_order_transmission_id', 'attempt_number'],
                'laboratory_attempt_transmission_number_unique',
            );
        });

        DB::table('laboratory_order_transmissions')
            ->where('attempt_count', '>', 0)
            ->orderBy('id')
            ->each(function (object $transmission): void {
                DB::table('laboratory_transmission_attempts')->insert([
                    'laboratory_order_transmission_id' => $transmission->id,
                    'attempt_number' => $transmission->attempt_count,
                    'status' => $transmission->status,
                    'http_status' => $transmission->last_http_status,
                    'request_hash' => $transmission->request_hash,
                    'response_hash' => $transmission->response_hash,
                    'error_code' => $transmission->error_code,
                    'error_message' => $transmission->last_error,
                    'started_at' => $transmission->last_attempt_at ?? $transmission->updated_at,
                    'finished_at' => $transmission->updated_at,
                    'created_at' => $transmission->updated_at,
                    'updated_at' => $transmission->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_transmission_attempts');
    }
};
