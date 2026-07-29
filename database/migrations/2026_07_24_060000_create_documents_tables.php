<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('verification_code', 32)->unique();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('encounter_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('medical_consultation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 48)->index();
            $table->string('title');
            $table->string('status', 24)->default('active')->index();
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->index(['health_unit_id', 'created_at']);
            $table->index(['encounter_id', 'document_type']);
        });

        Schema::create('document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->json('structured_content');
            $table->string('rendered_html_path');
            $table->string('pdf_path');
            $table->char('file_hash', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
