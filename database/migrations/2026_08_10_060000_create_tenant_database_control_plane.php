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
        Schema::create('tenant_databases', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('health_unit_id')->unique()->constrained('health_units')->cascadeOnDelete();
            $table->string('connection_profile', 100);
            $table->string('database_name')->nullable();
            $table->string('state', 24)->default('LEGACY')->index();
            $table->string('schema_status', 24)->default('pending')->index();
            $table->string('last_reconciliation_status', 24)->nullable()->index();
            $table->string('last_reconciliation_state', 24)->nullable();
            $table->json('last_reconciliation_summary')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('cutover_at')->nullable();
            $table->timestamp('tenant_at')->nullable();
            $table->timestamp('rollback_at')->nullable();
            $table->timestamp('backup_verified_at')->nullable();
            $table->timestamp('restore_tested_at')->nullable();
            $table->json('continuity_evidence')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_database_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('tenant_database_id')->constrained('tenant_databases')->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 64)->index();
            $table->string('from_state', 24)->nullable();
            $table->string('to_state', 24)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            $table->index(['tenant_database_id', 'occurred_at']);
        });

        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->ulid('health_unit_public_id')->nullable()->after('user_id')->index();
        });
        DB::table('idempotency_keys')->orderBy('id')->chunkById(500, function ($keys): void {
            foreach ($keys as $key) {
                $body = json_decode((string) $key->response_body, true);
                $encounterPublicId = is_array($body) ? ($body['encounter_public_id'] ?? null) : null;
                if (! is_string($encounterPublicId)) {
                    continue;
                }
                $unitPublicId = DB::table('encounters')
                    ->where('public_id', $encounterPublicId)
                    ->value('health_unit_public_id');
                if (is_string($unitPublicId)) {
                    DB::table('idempotency_keys')->where('id', $key->id)->update([
                        'health_unit_public_id' => $unitPublicId,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropColumn('health_unit_public_id');
        });
        Schema::dropIfExists('tenant_database_events');
        Schema::dropIfExists('tenant_databases');
    }
};
