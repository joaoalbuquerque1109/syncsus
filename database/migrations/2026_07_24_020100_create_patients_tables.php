<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('medical_record_number', 32)->unique();
            $table->string('full_name');
            $table->string('normalized_name')->index();
            $table->string('social_name')->nullable();
            $table->date('birth_date')->nullable()->index();
            $table->unsignedSmallInteger('estimated_age')->nullable();
            $table->string('estimated_age_range', 32)->nullable();
            $table->string('sex', 32);
            $table->string('gender_identity', 64)->nullable();
            $table->string('race_color', 32)->nullable();
            $table->string('ethnicity', 64)->nullable();
            $table->string('nationality', 64)->nullable();
            $table->string('birth_city', 120)->nullable();
            $table->string('marital_status', 32)->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->string('blood_type', 8)->nullable();
            $table->string('mother_name')->nullable();
            $table->string('normalized_mother_name')->nullable()->index();
            $table->boolean('mother_unknown')->default(false);
            $table->string('father_name')->nullable();
            $table->boolean('father_unknown')->default(false);
            $table->string('education_level', 64)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->foreignId('reference_health_unit_id')->nullable()->constrained('health_units')->nullOnDelete();
            $table->boolean('is_provisional')->default(false)->index();
            $table->text('provisional_description')->nullable();
            $table->foreignId('merged_into_patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('status', 32)->default('active')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('patient_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('normalized_value', 64);
            $table->string('display_value', 64);
            $table->string('issuer', 64)->nullable();
            $table->string('issuer_state', 2)->nullable();
            $table->date('issued_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'normalized_value']);
            $table->index(['patient_id', 'type']);
        });

        Schema::create('patient_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('value');
            $table->string('normalized_value')->nullable()->index();
            $table->boolean('is_primary')->default(false);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('postal_code', 16)->nullable();
            $table->string('country', 64)->default('Brasil');
            $table->string('state', 2)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('street_type', 32)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 32)->nullable();
            $table->string('complement')->nullable();
            $table->string('reference')->nullable();
            $table->string('area_type', 32)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->boolean('is_unknown')->default(false);
            $table->timestamps();
        });

        Schema::create('patient_guardians', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('cpf', 11)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('relationship', 64);
            $table->string('phone', 32)->nullable();
            $table->string('responsibility_reason')->nullable();
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_allergies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('substance');
            $table->string('reaction')->nullable();
            $table->string('severity', 32)->nullable();
            $table->string('status', 32)->default('active');
            $table->string('source', 32)->default('registration');
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        Schema::create('patient_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32)->nullable();
            $table->string('description');
            $table->string('status', 32)->default('active');
            $table->date('onset_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_conditions');
        Schema::dropIfExists('patient_allergies');
        Schema::dropIfExists('patient_guardians');
        Schema::dropIfExists('patient_addresses');
        Schema::dropIfExists('patient_contacts');
        Schema::dropIfExists('patient_identifiers');
        Schema::dropIfExists('patients');
    }
};
