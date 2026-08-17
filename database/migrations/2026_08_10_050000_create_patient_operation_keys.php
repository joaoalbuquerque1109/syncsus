<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_operation_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('route_name', 64);
            $table->string('idempotency_key', 64);
            $table->char('request_hash', 64);
            $table->ulid('patient_public_id')->nullable();
            $table->string('status', 24)->default('pending');
            $table->timestamps();

            $table->unique(
                ['user_id', 'route_name', 'idempotency_key'],
                'patient_operation_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_operation_keys');
    }
};
