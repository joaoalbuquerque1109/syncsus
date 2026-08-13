<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $localRecordTables = [
        'patient_contacts',
        'patient_addresses',
        'patient_guardians',
        'patient_allergies',
        'patient_conditions',
        'patient_medications',
        'patient_social_histories',
    ];

    public function up(): void
    {
        Schema::create('core_number_sequences', function (Blueprint $table): void {
            $table->id();
            $table->string('scope', 100);
            $table->string('date_key', 16)->default('');
            $table->unsignedBigInteger('current_value')->default(0);
            $table->timestamps();
            $table->unique(['scope', 'date_key']);
        });

        Schema::create('unit_patients', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->ulid('patient_public_id');
            $table->ulid('health_unit_public_id');
            $table->string('local_medical_record_number', 64)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['patient_public_id', 'health_unit_public_id'], 'unit_patients_patient_unit_unique');
            $table->index(['health_unit_public_id', 'status']);
        });

        foreach ($this->localRecordTables as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('unit_patient_id')->nullable()->after('patient_id')
                    ->constrained('unit_patients')->nullOnDelete();
                $table->ulid('patient_public_id')->nullable()->after('unit_patient_id')->index();
                $table->ulid('health_unit_public_id')->nullable()->after('patient_public_id')->index();
                $table->index(['unit_patient_id', 'created_at']);
            });
        }

        Schema::table('patient_social_histories', function (Blueprint $table): void {
            // patient_id foi criado com ->unique()->constrained(), então a FK usa o
            // próprio índice único como suporte. MySQL recusa dropUnique() enquanto
            // essa FK existir (SQLite não liga) — precisa derrubar a FK antes e
            // recriá-la depois que o novo índice único (unit_patient_id) já existir.
            $table->dropForeign(['patient_id']);
            $table->dropUnique(['patient_id']);
            $table->unique('unit_patient_id');
            $table->foreign('patient_id')->references('id')->on('patients')->cascadeOnDelete();
        });

        Schema::create('patient_unit_participations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('patient_public_id')->nullable();
            $table->ulid('health_unit_public_id');
            $table->string('tenant_connection');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['patient_public_id', 'health_unit_public_id'], 'patient_unit_participation_unique');
            $table->index(['health_unit_public_id', 'tenant_connection']);
        });

        Schema::create('patient_unit_migration_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('source_table');
            $table->unsignedBigInteger('source_id');
            $table->string('tenant_connection');
            $table->ulid('patient_public_id');
            $table->string('reason', 32)->index();
            $table->json('candidate_health_unit_public_ids');
            $table->string('status', 24)->default('pending')->index();
            $table->ulid('resolved_health_unit_public_id')->nullable();
            $table->ulid('resolved_by_public_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_connection', 'source_table', 'source_id'],
                'patient_unit_conflict_source_unique',
            );
            $table->index(['patient_public_id', 'status']);
        });

        Schema::table('patient_identifiers', function (Blueprint $table): void {
            $table->text('encrypted_value')->nullable()->after('display_value');
            $table->char('fingerprint', 64)->nullable()->after('encrypted_value')->index();
            $table->unsignedSmallInteger('fingerprint_key_version')->nullable()->after('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('patient_identifiers', function (Blueprint $table): void {
            $table->dropColumn(['encrypted_value', 'fingerprint', 'fingerprint_key_version']);
        });

        Schema::dropIfExists('patient_unit_migration_conflicts');
        Schema::dropIfExists('patient_unit_participations');

        Schema::table('patient_social_histories', function (Blueprint $table): void {
            $table->dropUnique(['unit_patient_id']);
            $table->unique('patient_id');
        });

        foreach (array_reverse($this->localRecordTables) as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('unit_patient_id');
                $table->dropColumn(['patient_public_id', 'health_unit_public_id']);
            });
        }

        Schema::dropIfExists('unit_patients');
        Schema::dropIfExists('core_number_sequences');
    }
};
