<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_order_transmissions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_integration_id')->constrained()->restrictOnDelete();
            $table->foreignId('exam_order_id')->constrained()->cascadeOnDelete();
            $table->string('external_order_number', 128)->nullable();
            $table->string('idempotency_key', 191)->unique();
            $table->string('status', 32)->default('awaiting_contract')->index();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->char('request_hash', 64)->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['laboratory_integration_id', 'exam_order_id'], 'lab_transmission_integration_order_unique');
            $table->index(['health_unit_id', 'status', 'next_attempt_at'], 'lab_transmission_unit_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_order_transmissions');
    }
};
