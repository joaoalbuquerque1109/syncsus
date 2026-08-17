<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('sus_procedure_code', 32)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->index(['organization_id', 'name'], 'exam_organization_name_index');
            $table->index(['organization_id', 'sus_procedure_code'], 'exam_organization_sus_index');
        });

        Schema::create('exam_mappings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_code', 128);
            $table->string('external_name_snapshot');
            $table->string('match_type', 16);
            $table->decimal('match_confidence', 5, 4)->nullable();
            $table->foreignId('mapped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('mapped_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(
                ['laboratory_integration_id', 'external_code'],
                'exam_mapping_integration_code_unique',
            );
            $table->index(['exam_id', 'is_active'], 'exam_mapping_exam_active_index');
        });

        Schema::create('health_unit_exams', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_enabled')->default(false)->index();
            $table->timestamp('enabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_id', 'health_unit_id'], 'health_unit_exam_unique');
            $table->index(['health_unit_id', 'is_enabled'], 'health_unit_exam_enabled_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_unit_exams');
        Schema::dropIfExists('exam_mappings');
        Schema::dropIfExists('exams');
    }
};
