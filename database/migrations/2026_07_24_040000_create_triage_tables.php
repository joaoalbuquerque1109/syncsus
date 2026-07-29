<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triage_protocols', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32);
            $table->string('name');
            $table->string('version', 32);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['code', 'version']);
        });

        Schema::create('triage_flowcharts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('triage_protocol_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['triage_protocol_id', 'code']);
        });

        Schema::create('triage_discriminators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('triage_flowchart_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->text('guidance')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['triage_flowchart_id', 'code']);
        });

        Schema::create('vital_sign_ranges', function (Blueprint $table): void {
            $table->id();
            $table->string('metric', 64)->unique();
            $table->string('label');
            $table->string('unit', 24);
            $table->decimal('hard_min', 10, 2)->nullable();
            $table->decimal('hard_max', 10, 2)->nullable();
            $table->decimal('warning_min', 10, 2)->nullable();
            $table->decimal('warning_max', 10, 2)->nullable();
            $table->string('configuration_version', 32);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('triage_assessments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('queue_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('service_point_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('chief_complaint')->nullable();
            $table->string('symptom_onset')->nullable();
            $table->text('brief_history')->nullable();
            $table->unsignedTinyInteger('pain_scale')->nullable();
            $table->boolean('has_reported_allergies')->nullable();
            $table->text('reported_allergies')->nullable();
            $table->boolean('uses_medications')->nullable();
            $table->text('current_medications')->nullable();
            $table->text('known_conditions')->nullable();
            $table->string('pregnancy_status', 32)->nullable();
            $table->string('fall_risk', 32)->nullable();
            $table->boolean('requires_isolation')->default(false);
            $table->string('isolation_reason')->nullable();
            $table->boolean('violence_signs')->default(false);
            $table->text('violence_notes')->nullable();
            $table->text('initial_exam')->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('triage_protocol_id')->nullable()->constrained()->nullOnDelete();
            $table->string('protocol_version', 32)->nullable();
            $table->foreignId('triage_flowchart_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('triage_discriminator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('risk_level_id')->nullable()->constrained()->nullOnDelete();
            $table->text('risk_justification')->nullable();
            $table->foreignId('destination_queue_id')->nullable()->constrained('queues')->nullOnDelete();
            $table->text('routing_notes')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vital_sign_measurements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('triage_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('source', 32)->default('triage');
            $table->timestamp('measured_at')->index();
            $table->unsignedSmallInteger('systolic_bp')->nullable();
            $table->unsignedSmallInteger('diastolic_bp')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->unsignedSmallInteger('respiratory_rate')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->decimal('oxygen_saturation', 5, 2)->nullable();
            $table->unsignedSmallInteger('blood_glucose')->nullable();
            $table->decimal('weight_kg', 6, 2)->nullable();
            $table->decimal('height_cm', 6, 2)->nullable();
            $table->decimal('bmi', 5, 2)->nullable();
            $table->unsignedTinyInteger('pain_scale')->nullable();
            $table->unsignedTinyInteger('glasgow_score')->nullable();
            $table->string('blood_type', 8)->nullable();
            $table->decimal('circumference_cm', 6, 2)->nullable();
            $table->json('clinical_alerts')->nullable();
            $table->json('technical_alerts')->nullable();
            $table->string('range_configuration_version', 32);
            $table->text('notes')->nullable();
            $table->boolean('corrected_by_addendum')->default(false);
            $table->timestamps();

            $table->index(['triage_assessment_id', 'measured_at']);
            $table->index(['encounter_id', 'measured_at']);
        });

        Schema::create('triage_addenda', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('triage_assessment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->text('content');
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triage_addenda');
        Schema::dropIfExists('vital_sign_measurements');
        Schema::dropIfExists('triage_assessments');
        Schema::dropIfExists('vital_sign_ranges');
        Schema::dropIfExists('triage_discriminators');
        Schema::dropIfExists('triage_flowcharts');
        Schema::dropIfExists('triage_protocols');
    }
};
