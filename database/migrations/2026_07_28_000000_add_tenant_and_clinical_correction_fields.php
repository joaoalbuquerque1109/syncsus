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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('public_id')
                ->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'is_active']);
        });
        Schema::table('patients', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('public_id')
                ->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'status']);
        });
        Schema::table('health_professionals', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable()->after('public_id')
                ->constrained()->restrictOnDelete();
            $table->index(['organization_id', 'is_active']);
        });

        $this->backfillOrganizations();

        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->foreignId('voided_by')->nullable()->after('void_reason')
                ->constrained('users')->nullOnDelete();
        });
        Schema::table('diagnoses', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->after('status');
            $table->text('void_reason')->nullable()->after('voided_at');
            $table->foreignId('voided_by')->nullable()->after('void_reason')
                ->constrained('users')->nullOnDelete();
        });
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->foreignId('cancelled_by')->nullable()->after('cancellation_reason')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancelled_by');
        });
        Schema::table('diagnoses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
        Schema::table('clinical_notes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
        });
        Schema::table('health_professionals', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('patients', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'status']);
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'is_active']);
            $table->dropConstrainedForeignId('organization_id');
        });
    }

    private function backfillOrganizations(): void
    {
        $defaultOrganizationId = DB::table('organizations')->orderBy('id')->value('id');
        if ($defaultOrganizationId === null) {
            return;
        }

        DB::table('users')->orderBy('id')->each(function (object $user) use ($defaultOrganizationId): void {
            $organizationId = DB::table('health_units')
                ->where('id', $user->default_health_unit_id)
                ->value('organization_id');
            $organizationId ??= DB::table('health_unit_user')
                ->join('health_units', 'health_units.id', '=', 'health_unit_user.health_unit_id')
                ->where('health_unit_user.user_id', $user->id)
                ->value('health_units.organization_id');
            DB::table('users')->where('id', $user->id)->update([
                'organization_id' => $organizationId ?? $defaultOrganizationId,
            ]);
        });

        DB::table('patients')->orderBy('id')->each(function (object $patient) use ($defaultOrganizationId): void {
            $organizationId = DB::table('health_units')
                ->where('id', $patient->reference_health_unit_id)
                ->value('organization_id');
            DB::table('patients')->where('id', $patient->id)->update([
                'organization_id' => $organizationId ?? $defaultOrganizationId,
            ]);
        });

        DB::table('health_professionals')->orderBy('id')->each(function (object $professional) use ($defaultOrganizationId): void {
            $organizationId = DB::table('users')
                ->where('id', $professional->user_id)
                ->value('organization_id');
            $organizationId ??= DB::table('health_professional_health_unit')
                ->join('health_units', 'health_units.id', '=', 'health_professional_health_unit.health_unit_id')
                ->where('health_professional_health_unit.health_professional_id', $professional->id)
                ->value('health_units.organization_id');
            DB::table('health_professionals')->where('id', $professional->id)->update([
                'organization_id' => $organizationId ?? $defaultOrganizationId,
            ]);
        });
    }
};
