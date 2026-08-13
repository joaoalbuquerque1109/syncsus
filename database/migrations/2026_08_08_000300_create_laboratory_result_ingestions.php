<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_integrations', function (Blueprint $table): void {
            $table->char('result_api_token_hash', 64)->nullable()->unique()->after('password');
            $table->timestamp('result_api_token_rotated_at')->nullable()->after('result_api_token_hash');
        });

        Schema::create('laboratory_result_ingestions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('laboratory_integration_id')->constrained()->restrictOnDelete();
            $table->foreignId('laboratory_order_transmission_id')->nullable()
                ->constrained(indexName: 'lab_result_ingestion_transmission_foreign')->nullOnDelete();
            $table->foreignId('exam_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_order_number', 128)->nullable();
            $table->string('external_exam_code', 128)->nullable();
            $table->string('external_result_reference', 191)->nullable();
            $table->char('idempotency_key', 64);
            $table->char('content_hash', 64);
            $table->string('status', 32)->default('received')->index();
            $table->longText('payload');
            $table->longText('validation_errors')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('received_at')->index();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('last_duplicate_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['laboratory_integration_id', 'idempotency_key'],
                'lab_result_ingestion_idempotency_unique',
            );
            $table->index(
                ['laboratory_integration_id', 'external_order_number'],
                'lab_result_ingestion_order_index',
            );
        });

        Schema::table('exam_results', function (Blueprint $table): void {
            $table->foreignId('recorded_by')->nullable()->change();
            $table->string('source', 24)->default('manual')->after('exam_order_item_id')->index();
            $table->foreignId('laboratory_result_ingestion_id')->nullable()->unique()
                ->after('source')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        DB::table('exam_results')->where('source', 'synclab')->delete();
        Schema::table('exam_results', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('laboratory_result_ingestion_id');
            $table->dropColumn('source');
            $table->foreignId('recorded_by')->nullable(false)->change();
        });

        Schema::dropIfExists('laboratory_result_ingestions');

        Schema::table('laboratory_integrations', function (Blueprint $table): void {
            $table->dropUnique(['result_api_token_hash']);
            $table->dropColumn(['result_api_token_hash', 'result_api_token_rotated_at']);
        });
    }
};
