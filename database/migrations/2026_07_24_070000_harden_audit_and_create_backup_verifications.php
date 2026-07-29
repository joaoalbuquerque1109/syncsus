<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['health_unit_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['patient_id', 'occurred_at']);
            $table->index(['encounter_id', 'occurred_at']);
        });

        Schema::table('patient_access_logs', function (Blueprint $table): void {
            $table->index(['health_unit_id', 'occurred_at']);
            $table->index(['patient_id', 'occurred_at']);
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::table('encounters', function (Blueprint $table): void {
            $table->index(['health_unit_id', 'arrival_at']);
        });

        Schema::create('backup_verifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('backup_set');
            $table->string('status', 24)->index();
            $table->json('checks')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_verifications');

        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropIndex(['health_unit_id', 'arrival_at']);
        });

        Schema::table('patient_access_logs', function (Blueprint $table): void {
            $table->dropIndex(['health_unit_id', 'occurred_at']);
            $table->dropIndex(['patient_id', 'occurred_at']);
            $table->dropIndex(['user_id', 'occurred_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex(['health_unit_id', 'occurred_at']);
            $table->dropIndex(['user_id', 'occurred_at']);
            $table->dropIndex(['patient_id', 'occurred_at']);
            $table->dropIndex(['encounter_id', 'occurred_at']);
        });
    }
};
