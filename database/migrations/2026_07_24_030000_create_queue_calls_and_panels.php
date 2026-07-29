<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queues', function (Blueprint $table): void {
            $table->unsignedSmallInteger('minimum_calls_before_absent')->default(1)->after('priority_strategy');
            $table->unsignedSmallInteger('ticket_length')->default(3)->after('prefix');
        });

        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->foreignId('assigned_user_id')->nullable()->after('service_point_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('queue_service_point', function (Blueprint $table): void {
            $table->foreignId('queue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_point_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['queue_id', 'service_point_id']);
        });

        Schema::create('queue_calls', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('queue_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_id')->constrained()->restrictOnDelete();
            $table->foreignId('service_point_id')->constrained()->restrictOnDelete();
            $table->foreignId('called_by')->constrained('users')->restrictOnDelete();
            $table->string('call_type', 16);
            $table->unsignedSmallInteger('call_number');
            $table->string('ticket_snapshot', 16);
            $table->timestamp('called_at')->index();
            $table->timestamps();

            $table->index(['queue_id', 'id']);
            $table->index(['queue_entry_id', 'called_at']);
        });

        Schema::create('queue_entry_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('queue_entry_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('action', 32);
            $table->foreignId('service_point_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by')->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();

            $table->index(['queue_entry_id', 'occurred_at']);
        });

        Schema::create('queue_transfers', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_entry_id')->constrained('queue_entries')->restrictOnDelete();
            $table->foreignId('destination_entry_id')->constrained('queue_entries')->restrictOnDelete();
            $table->foreignId('source_queue_id')->constrained('queues')->restrictOnDelete();
            $table->foreignId('destination_queue_id')->constrained('queues')->restrictOnDelete();
            $table->foreignId('transferred_by')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->boolean('priority_preserved')->default(true);
            $table->timestamp('transferred_at')->index();
            $table->timestamps();
        });

        Schema::create('panels', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('public_code', 64)->unique();
            $table->string('identification_mode', 32)->default('ticket_only');
            $table->unsignedSmallInteger('previous_calls_count')->default(5);
            $table->boolean('sound_enabled')->default(true);
            $table->unsignedSmallInteger('suggested_volume')->default(80);
            $table->string('theme', 32)->default('institutional');
            $table->string('institutional_message')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_heartbeat_at')->nullable()->index();
            $table->unsignedBigInteger('heartbeat_count')->default(0);
            $table->timestamps();
        });

        Schema::create('panel_queue', function (Blueprint $table): void {
            $table->foreignId('panel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['panel_id', 'queue_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_queue');
        Schema::dropIfExists('panels');
        Schema::dropIfExists('queue_transfers');
        Schema::dropIfExists('queue_entry_history');
        Schema::dropIfExists('queue_calls');
        Schema::dropIfExists('queue_service_point');

        Schema::table('queue_entries', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('assigned_user_id');
        });
        Schema::table('queues', function (Blueprint $table): void {
            $table->dropColumn(['minimum_calls_before_absent', 'ticket_length']);
        });
    }
};
