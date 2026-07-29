<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosis_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 16)->unique();
            $table->string('description');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('medical_consultations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('queue_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('professional_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedInteger('lock_version')->default(1);
            $table->text('chief_complaint')->nullable();
            $table->text('present_illness_history')->nullable();
            $table->text('personal_history')->nullable();
            $table->text('family_history')->nullable();
            $table->text('surgical_history')->nullable();
            $table->text('current_medications')->nullable();
            $table->text('allergies_summary')->nullable();
            $table->text('habits')->nullable();
            $table->text('gynecological_history')->nullable();
            $table->text('review_of_systems')->nullable();
            $table->text('additional_notes')->nullable();
            $table->text('conduct_summary')->nullable();
            $table->text('procedures_summary')->nullable();
            $table->text('guidance')->nullable();
            $table->boolean('requires_reassessment')->default(false);
            $table->text('physical_exam_justification')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('physical_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('medical_consultation_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('general_state')->nullable();
            $table->text('consciousness')->nullable();
            $table->text('skin_mucosa')->nullable();
            $table->text('head_neck')->nullable();
            $table->text('respiratory')->nullable();
            $table->text('cardiovascular')->nullable();
            $table->text('abdomen')->nullable();
            $table->text('neurological')->nullable();
            $table->text('musculoskeletal')->nullable();
            $table->text('extremities')->nullable();
            $table->text('specific_findings')->nullable();
            $table->text('free_text')->nullable();
            $table->timestamps();
        });

        Schema::create('diagnoses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('diagnosis_code_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 16)->nullable();
            $table->string('description');
            $table->string('diagnosis_type', 24);
            $table->boolean('is_primary')->default(false);
            $table->string('status', 24)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('diagnosed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('diagnosed_at')->index();
            $table->timestamps();

            $table->index(['medical_consultation_id', 'is_primary', 'status']);
        });

        Schema::create('prescriptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('professional_id')->constrained('users')->restrictOnDelete();
            $table->string('prescription_type', 24);
            $table->string('status', 24)->default('finalized')->index();
            $table->text('general_instructions')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('prescription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained()->cascadeOnDelete();
            $table->string('medication_name');
            $table->string('presentation');
            $table->string('concentration')->nullable();
            $table->decimal('dose', 10, 3);
            $table->string('dose_unit', 32);
            $table->string('route', 64);
            $table->string('frequency', 128);
            $table->string('interval_text')->nullable();
            $table->unsignedSmallInteger('duration_value')->nullable();
            $table->string('duration_unit', 32)->nullable();
            $table->string('quantity', 64)->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('is_immediate')->default(false);
            $table->boolean('is_as_needed')->default(false);
            $table->text('as_needed_condition')->nullable();
            $table->text('dilution')->nullable();
            $table->string('infusion_rate')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        Schema::create('exam_orders', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('priority', 24)->default('routine');
            $table->text('clinical_indication');
            $table->text('notes')->nullable();
            $table->timestamp('requested_at')->index();
            $table->timestamps();
        });

        Schema::create('exam_order_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_order_id')->constrained()->cascadeOnDelete();
            $table->string('internal_code', 32)->nullable();
            $table->string('exam_name');
            $table->string('group', 32);
            $table->string('priority', 24)->default('routine');
            $table->string('laterality', 32)->nullable();
            $table->text('preparation')->nullable();
            $table->text('justification')->nullable();
            $table->string('status', 32)->default('requested')->index();
            $table->timestamps();
        });

        Schema::create('exam_results', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('exam_order_item_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('result_text');
            $table->text('conclusion')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('resulted_at')->index();
            $table->text('notes')->nullable();
            $table->char('content_hash', 64);
            $table->timestamps();
        });

        Schema::create('clinical_notes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note_type', 32);
            $table->text('content');
            $table->timestamp('clinical_at')->index();
            $table->string('status', 24)->default('finalized');
            $table->timestamp('finalized_at');
            $table->foreignId('parent_note_id')->nullable()->constrained('clinical_notes')->nullOnDelete();
            $table->string('addendum_reason')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('specialty_id')->nullable()->constrained()->nullOnDelete();
            $table->string('referral_type', 24);
            $table->string('destination');
            $table->string('recipient_professional')->nullable();
            $table->text('reason');
            $table->text('clinical_summary');
            $table->string('priority', 24)->default('routine');
            $table->text('diagnostic_hypothesis')->nullable();
            $table->text('guidance')->nullable();
            $table->string('status', 24)->default('issued');
            $table->timestamp('issued_at')->index();
            $table->timestamps();
        });

        Schema::create('encounter_destinations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('encounter_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->unique()->constrained()->restrictOnDelete();
            $table->string('destination_type', 32)->index();
            $table->text('reason');
            $table->text('clinical_condition')->nullable();
            $table->text('clinical_summary')->nullable();
            $table->text('instructions')->nullable();
            $table->text('warning_signs')->nullable();
            $table->string('follow_up')->nullable();
            $table->string('destination_institution')->nullable();
            $table->string('destination_city')->nullable();
            $table->string('destination_department')->nullable();
            $table->string('bed_type')->nullable();
            $table->string('transport_method')->nullable();
            $table->string('last_known_location')->nullable();
            $table->text('contact_attempts')->nullable();
            $table->text('death_cause')->nullable();
            $table->json('details')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('medical_addenda', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->string('reason');
            $table->text('content');
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_addenda');
        Schema::dropIfExists('encounter_destinations');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('clinical_notes');
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_order_items');
        Schema::dropIfExists('exam_orders');
        Schema::dropIfExists('prescription_items');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('diagnoses');
        Schema::dropIfExists('physical_exams');
        Schema::dropIfExists('medical_consultations');
        Schema::dropIfExists('diagnosis_codes');
    }
};
