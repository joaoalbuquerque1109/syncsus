<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 32);
            $table->boolean('is_clinical')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['health_unit_id', 'code']);
        });

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('room_type', 32);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['department_id', 'code']);
        });

        Schema::create('service_points', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('type', 32);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['room_id', 'code']);
        });

        Schema::create('specialties', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('arrival_methods', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('requires_vehicle_data')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('risk_levels', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->string('color_key', 32);
            $table->unsignedSmallInteger('reference_minutes');
            $table->unsignedSmallInteger('priority_weight');
            $table->string('protocol_version', 32);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('queues', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('prefix', 8);
            $table->string('sequence_reset_policy', 32)->default('daily');
            $table->string('priority_strategy', 32)->default('fifo');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['health_unit_id', 'code']);
        });

        Schema::create('entry_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->boolean('requires_triage')->default(true);
            $table->boolean('allows_provisional_patient')->default(true);
            $table->foreignId('default_queue_id')->nullable()->constrained('queues')->nullOnDelete();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_types');
        Schema::dropIfExists('queues');
        Schema::dropIfExists('risk_levels');
        Schema::dropIfExists('arrival_methods');
        Schema::dropIfExists('specialties');
        Schema::dropIfExists('service_points');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('departments');
    }
};
