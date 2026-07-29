<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_professionals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('institutional_code', 32)->unique();
            $table->string('profession_type', 32)->index();
            $table->string('treatment_name', 32)->nullable();
            $table->string('full_name');
            $table->string('social_name')->nullable();
            $table->string('cpf', 11)->nullable()->unique();
            $table->date('birth_date')->nullable();
            $table->string('sex', 32)->nullable();
            $table->string('identity_number', 32)->nullable();
            $table->string('identity_issuer', 32)->nullable();
            $table->string('identity_state', 2)->nullable();
            $table->string('cnes_code', 20)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('secondary_phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->string('secondary_email')->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('city_ibge_code', 16)->nullable()->index();
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->string('street_number', 32)->nullable();
            $table->string('address_complement')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('professional_registrations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('health_professional_id')->constrained()->cascadeOnDelete();
            $table->string('council_type', 16);
            $table->string('registration_number', 32);
            $table->string('state', 2);
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(
                ['council_type', 'registration_number', 'state'],
                'professional_registration_unique',
            );
        });

        Schema::create('health_professional_specialty', function (Blueprint $table): void {
            $table->foreignId('health_professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $table->string('rqe_number', 32)->nullable();
            $table->date('registered_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->primary(['health_professional_id', 'specialty_id'], 'professional_specialty_primary');
        });

        Schema::create('health_professional_health_unit', function (Blueprint $table): void {
            $table->foreignId('health_professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['health_professional_id', 'health_unit_id'], 'professional_unit_primary');
        });

        Schema::table('patients', function (Blueprint $table): void {
            $table->string('birth_city_ibge_code', 16)->nullable()->after('birth_city')->index();
            $table->unsignedSmallInteger('number_of_children')->nullable()->after('marital_status');
            $table->text('administrative_notes')->nullable()->after('occupation');
        });

        Schema::table('patient_addresses', function (Blueprint $table): void {
            $table->string('city_ibge_code', 16)->nullable()->after('city')->index();
        });

        Schema::table('patient_guardians', function (Blueprint $table): void {
            $table->string('guardian_type', 24)->default('legal')->after('patient_id')->index();
        });

        Schema::create('patient_medications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('medication_name');
            $table->string('dosage')->nullable();
            $table->string('frequency')->nullable();
            $table->string('route', 64)->nullable();
            $table->string('status', 24)->default('active')->index();
            $table->string('source', 32)->default('patient_record');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });

        Schema::create('patient_social_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('smoking_status', 32)->nullable();
            $table->string('alcohol_use', 32)->nullable();
            $table->string('other_substance_use', 32)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_social_histories');
        Schema::dropIfExists('patient_medications');

        Schema::table('patient_guardians', function (Blueprint $table): void {
            $table->dropColumn('guardian_type');
        });
        Schema::table('patient_addresses', function (Blueprint $table): void {
            $table->dropColumn('city_ibge_code');
        });
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropColumn(['birth_city_ibge_code', 'number_of_children', 'administrative_notes']);
        });

        Schema::dropIfExists('health_professional_health_unit');
        Schema::dropIfExists('health_professional_specialty');
        Schema::dropIfExists('professional_registrations');
        Schema::dropIfExists('health_professionals');
    }
};
