<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
        });
        Schema::table('exam_orders', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
        });
        Schema::table('referrals', function (Blueprint $table): void {
            $table->foreignId('document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
        });

        Schema::create('medical_certificates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('duration_value');
            $table->string('duration_unit', 16);
            $table->text('statement');
            $table->text('additional_information')->nullable();
            $table->boolean('include_cid')->default(false);
            $table->foreignId('diagnosis_code_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cid_authorized_at')->nullable();
            $table->string('status', 24)->default('issued')->index();
            $table->timestamp('issued_at')->index();
            $table->foreignId('document_id')->nullable()->unique()->constrained('documents')->restrictOnDelete();
            $table->timestamps();

            $table->index(['medical_consultation_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_certificates');

        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
        });
        Schema::table('exam_orders', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
        });
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('document_id');
        });
    }
};
