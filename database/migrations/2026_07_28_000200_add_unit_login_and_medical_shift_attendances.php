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
        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('code', 32)->nullable()->after('public_id');
        });

        DB::table('organizations')->orderBy('id')->each(function (object $organization): void {
            $unitCode = DB::table('health_units')
                ->where('organization_id', $organization->id)
                ->orderBy('id')
                ->value('code');
            $base = mb_strtoupper((string) ($unitCode ?: 'UNIDADE-'.$organization->id));
            $code = $base;
            $suffix = 2;
            while (DB::table('organizations')->where('code', $code)->where('id', '!=', $organization->id)->exists()) {
                $code = $base.'-'.$suffix++;
            }
            DB::table('organizations')->where('id', $organization->id)->update(['code' => $code]);
        });

        Schema::table('organizations', function (Blueprint $table): void {
            $table->string('code', 32)->nullable(false)->change();
            $table->unique('code');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
            $table->unique(['organization_id', 'email'], 'users_organization_email_unique');
        });

        Schema::table('health_professionals', function (Blueprint $table): void {
            $table->dropUnique('health_professionals_institutional_code_unique');
            $table->dropUnique('health_professionals_cpf_unique');
            $table->unique(
                ['organization_id', 'institutional_code'],
                'health_professionals_org_institutional_unique',
            );
            $table->unique(['organization_id', 'cpf'], 'health_professionals_org_cpf_unique');
        });

        Schema::table('professional_registrations', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('public_id')
                ->constrained()->restrictOnDelete();
        });
        DB::table('professional_registrations')->orderBy('id')->each(
            function (object $registration): void {
                $organizationId = DB::table('health_professionals')
                    ->where('id', $registration->health_professional_id)
                    ->value('organization_id');
                DB::table('professional_registrations')
                    ->where('id', $registration->id)
                    ->update(['organization_id' => $organizationId]);
            },
        );
        Schema::table('professional_registrations', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->dropUnique('professional_registration_unique');
            $table->unique(
                ['organization_id', 'council_type', 'registration_number', 'state'],
                'professional_registration_tenant_unique',
            );
        });

        Schema::create('medical_shift_attendances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('duty_date')->index();
            $table->timestamp('checked_in_at');
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('checkout_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(
                ['health_unit_id', 'user_id', 'duty_date'],
                'medical_shift_attendance_daily_unique',
            );
            $table->index(
                ['organization_id', 'health_unit_id', 'duty_date', 'checked_out_at'],
                'medical_shift_attendance_active_index',
            );
        });

        Schema::table('triage_assessments', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('finalized_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
        Schema::table('medical_consultations', function (Blueprint $table): void {
            $table->timestamp('cancelled_at')->nullable()->after('finalized_by');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('medical_consultations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
        Schema::table('triage_assessments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
        });
        Schema::dropIfExists('medical_shift_attendances');

        Schema::table('professional_registrations', function (Blueprint $table): void {
            $table->dropUnique('professional_registration_tenant_unique');
            $table->unique(
                ['council_type', 'registration_number', 'state'],
                'professional_registration_unique',
            );
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('health_professionals', function (Blueprint $table): void {
            $table->dropUnique('health_professionals_org_institutional_unique');
            $table->dropUnique('health_professionals_org_cpf_unique');
            $table->unique('institutional_code');
            $table->unique('cpf');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_organization_email_unique');
            $table->unique('email');
        });
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropUnique('organizations_code_unique');
            $table->dropColumn('code');
        });
    }
};
