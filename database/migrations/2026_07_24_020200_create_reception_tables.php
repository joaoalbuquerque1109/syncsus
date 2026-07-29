<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('date_key', 16)->default('');
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(['scope', 'date_key']);
        });

        Schema::create('encounters', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('encounter_number', 32)->unique();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('entry_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('arrival_method_id')->constrained()->restrictOnDelete();
            $table->string('current_status', 32)->index();
            $table->foreignId('risk_level_id')->nullable()->constrained()->nullOnDelete();
            $table->string('administrative_priority', 32)->default('none');
            $table->timestamp('arrival_at')->index();
            $table->timestamp('registration_at');
            $table->timestamp('triage_started_at')->nullable();
            $table->timestamp('triage_finished_at')->nullable();
            $table->timestamp('medical_started_at')->nullable();
            $table->timestamp('medical_finished_at')->nullable();
            $table->timestamp('observation_started_at')->nullable();
            $table->timestamp('closed_at')->nullable()->index();
            $table->foreignId('assigned_specialty_id')->nullable()->constrained('specialties')->nullOnDelete();
            $table->foreignId('current_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('current_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'current_status']);
            $table->index(['health_unit_id', 'current_status', 'arrival_at']);
        });

        Schema::create('reception_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->restrictOnDelete();
            $table->string('origin')->nullable();
            $table->string('entry_reason');
            $table->text('reception_notes')->nullable();
            $table->string('vehicle_information')->nullable();
            $table->string('origin_institution')->nullable();
            $table->string('regulation_number', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('encounter_companions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('cpf', 11)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('relationship', 64)->nullable();
            $table->boolean('is_legal_guardian')->default(false);
            $table->timestamp('authorized_at');
            $table->timestamps();
        });

        Schema::create('encounter_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at')->index();
        });

        Schema::create('queue_sequences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('queue_id')->constrained()->cascadeOnDelete();
            $table->date('sequence_date');
            $table->unsignedInteger('current_value')->default(0);
            $table->timestamps();

            $table->unique(['queue_id', 'sequence_date']);
        });

        Schema::create('queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('queue_id')->constrained()->restrictOnDelete();
            $table->string('ticket_number', 16);
            $table->unsignedSmallInteger('priority_weight')->default(0);
            $table->string('status', 32)->index();
            $table->timestamp('entered_at')->index();
            $table->timestamp('first_called_at')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamp('exited_at')->nullable();
            $table->unsignedSmallInteger('call_count')->default(0);
            $table->foreignId('service_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('exit_reason')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();

            $table->index(['queue_id', 'status', 'priority_weight', 'entered_at']);
            $table->index(['encounter_id', 'status']);
            $table->unique(['queue_id', 'ticket_number', 'entered_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('route_name', 120);
            $table->string('idempotency_key', 64);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'route_name', 'idempotency_key']);
        });

        Schema::create('patient_access_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->string('access_type', 64);
            $table->string('purpose')->nullable();
            $table->string('route_name', 120);
            $table->timestamp('occurred_at')->index();
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->foreignId('patient_id')->nullable()->after('health_unit_id')->constrained()->nullOnDelete();
            $table->foreignId('encounter_id')->nullable()->after('patient_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('encounter_id');
            $table->dropConstrainedForeignId('patient_id');
        });
        Schema::dropIfExists('patient_access_logs');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('queue_entries');
        Schema::dropIfExists('queue_sequences');
        Schema::dropIfExists('encounter_status_history');
        Schema::dropIfExists('encounter_companions');
        Schema::dropIfExists('reception_records');
        Schema::dropIfExists('encounters');
        Schema::dropIfExists('number_sequences');
    }
};
