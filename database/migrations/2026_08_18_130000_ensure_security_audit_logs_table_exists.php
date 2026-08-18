<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('core')->hasTable('security_audit_logs')) {
            return;
        }

        Schema::connection('core')->create('security_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('health_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 100)->index();
            $table->ulid('correlation_id')->index();
            $table->nullableMorphs('auditable');
            $table->json('changed_fields')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('core')->dropIfExists('security_audit_logs');
    }
};
