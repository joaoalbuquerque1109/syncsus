<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratory_integrations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->string('provider', 32)->default('synclab');
            $table->string('external_tenant_code', 64)->nullable();
            $table->string('base_url')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false)->index();
            $table->boolean('transmission_enabled')->default(false);
            $table->boolean('result_sync_enabled')->default(false);
            $table->string('connection_status', 24)->default('not_tested')->index();
            $table->timestamp('last_connection_test_at')->nullable();
            $table->timestamp('last_catalog_sync_at')->nullable();
            $table->string('catalog_cursor')->nullable();
            $table->string('catalog_version')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['health_unit_id', 'provider']);
            $table->index(['organization_id', 'provider']);
        });

        Schema::create('laboratory_materials', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laboratory_integration_id')->constrained()->cascadeOnDelete();
            $table->string('external_code', 128);
            $table->string('name');
            $table->string('origin')->nullable();
            $table->string('container')->nullable();
            $table->string('cap_color', 64)->nullable();
            $table->text('collection_instructions')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('source_version')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['laboratory_integration_id', 'external_code'], 'lab_material_integration_code_unique');
        });

        Schema::create('laboratory_exams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laboratory_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('laboratory_material_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_code', 128);
            $table->string('acronym', 64)->nullable();
            $table->string('integration_acronym', 128)->nullable();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('sus_procedure_code', 32)->nullable();
            $table->string('group_code', 64)->nullable();
            $table->string('group_name')->nullable();
            $table->string('sex_restriction', 16)->nullable();
            $table->unsignedInteger('turnaround_minutes')->nullable();
            $table->text('collection_instructions')->nullable();
            $table->json('synonyms')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->string('source_version')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();

            $table->unique(['laboratory_integration_id', 'external_code'], 'lab_exam_integration_code_unique');
            $table->index(['laboratory_integration_id', 'name']);
            $table->index(['laboratory_integration_id', 'acronym']);
            $table->index(['laboratory_integration_id', 'sus_procedure_code'], 'lab_exam_integration_sus_index');
        });

        Schema::create('laboratory_exam_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('laboratory_exam_id')->constrained()->cascadeOnDelete();
            $table->string('external_code', 128);
            $table->string('interface_acronym', 128)->nullable();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->string('data_type', 32)->nullable();
            $table->string('unit', 64)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->string('source_version')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(['laboratory_exam_id', 'external_code'], 'lab_component_exam_code_unique');
        });

        Schema::table('exam_order_items', function (Blueprint $table): void {
            $table->foreignId('laboratory_integration_id')->nullable()->after('exam_order_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('laboratory_exam_id')->nullable()->after('laboratory_integration_id')
                ->constrained()->nullOnDelete();
            $table->string('external_exam_code', 128)->nullable()->after('internal_code');
            $table->string('catalog_version')->nullable()->after('external_exam_code');
            $table->json('laboratory_snapshot')->nullable()->after('catalog_version');
            $table->index(['laboratory_integration_id', 'status'], 'exam_item_lab_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('exam_order_items', function (Blueprint $table): void {
            $table->dropIndex('exam_item_lab_status_index');
            $table->dropConstrainedForeignId('laboratory_exam_id');
            $table->dropConstrainedForeignId('laboratory_integration_id');
            $table->dropColumn(['external_exam_code', 'catalog_version', 'laboratory_snapshot']);
        });
        Schema::dropIfExists('laboratory_exam_components');
        Schema::dropIfExists('laboratory_exams');
        Schema::dropIfExists('laboratory_materials');
        Schema::dropIfExists('laboratory_integrations');
    }
};
