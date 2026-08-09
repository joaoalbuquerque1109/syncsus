<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_catalog_import_candidates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_exam_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('suggested_exam_id')->nullable()->constrained('exams')->nullOnDelete();
            $table->foreignId('existing_mapping_id')->nullable()->constrained('exam_mappings')->nullOnDelete();
            $table->string('match_status', 24)->index();
            $table->decimal('match_confidence', 5, 4)->nullable();
            $table->string('reason', 64);
            $table->string('source_version')->nullable();
            $table->char('source_hash', 64);
            $table->string('resolution', 24)->nullable()->index();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(
                ['laboratory_integration_id', 'match_status', 'resolution'],
                'exam_import_candidate_review_index',
            );
        });

        Schema::create('exam_groups', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('normalized_name');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['organization_id', 'normalized_name'], 'exam_group_organization_name_unique');
        });

        Schema::create('exam_group_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('exam_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_group_id', 'exam_id'], 'exam_group_item_unique');
        });

        Schema::create('exam_group_import_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('exam_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_group_code', 128)->nullable();
            $table->string('external_name');
            $table->string('normalized_name');
            $table->string('conflict_type', 32)->index();
            $table->json('source_items');
            $table->json('current_items')->nullable();
            $table->json('missing_external_codes')->nullable();
            $table->json('added_exam_ids')->nullable();
            $table->json('removed_exam_ids')->nullable();
            $table->char('source_hash', 64);
            $table->string('status', 16)->default('pending')->index();
            $table->string('decision', 16)->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_integration_id', 'source_hash'],
                'exam_group_conflict_source_unique',
            );
            $table->index(
                ['organization_id', 'status', 'created_at'],
                'exam_group_conflict_review_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_group_import_conflicts');
        Schema::dropIfExists('exam_group_items');
        Schema::dropIfExists('exam_groups');
        Schema::dropIfExists('exam_catalog_import_candidates');
    }
};
