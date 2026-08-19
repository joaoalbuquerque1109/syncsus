<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('core')->hasTable('unit_report_snapshots')) {
            Schema::connection('core')->create('unit_report_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
                $table->ulid('health_unit_public_id')->index();
                $table->date('period_date')->index();
                $table->json('metrics');
                $table->string('source_connection');
                $table->timestamp('generated_at')->index();
                $table->timestamps();

                $table->unique(['health_unit_id', 'period_date']);
            });
        }

        if (! Schema::connection('core')->hasColumn('backup_logs', 'tenant_database_id')) {
            Schema::connection('core')->table('backup_logs', function (Blueprint $table): void {
                $table->foreignId('tenant_database_id')->nullable()->after('public_id')
                    ->constrained('tenant_databases')->nullOnDelete();
                $table->foreignId('health_unit_id')->nullable()->after('tenant_database_id')
                    ->constrained()->nullOnDelete();
                $table->string('backup_scope', 16)->default('core')->after('health_unit_id')->index();
                $table->timestamp('core_reference_at')->nullable()->after('backup_scope');
            });
        }

        if (! Schema::connection('core')->hasColumn('backup_verifications', 'tenant_database_id')) {
            Schema::connection('core')->table('backup_verifications', function (Blueprint $table): void {
                $table->foreignId('tenant_database_id')->nullable()->after('public_id')
                    ->constrained('tenant_databases')->nullOnDelete();
                $table->foreignId('health_unit_id')->nullable()->after('tenant_database_id')
                    ->constrained()->nullOnDelete();
                $table->string('backup_scope', 16)->default('core')->after('health_unit_id')->index();
                $table->timestamp('core_reference_at')->nullable()->after('backup_scope');
                $table->timestamp('restore_point_at')->nullable()->after('core_reference_at');
                $table->boolean('restore_compatible')->nullable()->after('restore_point_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('core')->hasColumn('backup_verifications', 'tenant_database_id')) {
            Schema::connection('core')->table('backup_verifications', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('tenant_database_id');
                $table->dropConstrainedForeignId('health_unit_id');
                $table->dropColumn(['backup_scope', 'core_reference_at', 'restore_point_at', 'restore_compatible']);
            });
        }
        if (Schema::connection('core')->hasColumn('backup_logs', 'tenant_database_id')) {
            Schema::connection('core')->table('backup_logs', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('tenant_database_id');
                $table->dropConstrainedForeignId('health_unit_id');
                $table->dropColumn(['backup_scope', 'core_reference_at']);
            });
        }
        Schema::connection('core')->dropIfExists('unit_report_snapshots');
    }
};
