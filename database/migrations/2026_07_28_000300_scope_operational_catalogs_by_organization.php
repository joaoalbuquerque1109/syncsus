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
        foreach (['specialties', 'arrival_methods', 'entry_types'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('organization_id')->nullable()->after('id')
                    ->constrained()->restrictOnDelete();
            });
        }

        $defaultOrganizationId = DB::table('organizations')->orderBy('id')->value('id');
        if ($defaultOrganizationId !== null) {
            foreach (['specialties', 'arrival_methods', 'entry_types'] as $tableName) {
                DB::table($tableName)->whereNull('organization_id')
                    ->update(['organization_id' => $defaultOrganizationId]);
            }
        }

        Schema::table('specialties', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->dropUnique('specialties_code_unique');
            $table->unique(['organization_id', 'code'], 'specialties_organization_code_unique');
        });
        Schema::table('arrival_methods', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->dropUnique('arrival_methods_code_unique');
            $table->unique(['organization_id', 'code'], 'arrival_methods_organization_code_unique');
        });
        Schema::table('entry_types', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
            $table->dropUnique('entry_types_code_unique');
            $table->unique(['organization_id', 'code'], 'entry_types_organization_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('entry_types', function (Blueprint $table): void {
            $table->dropUnique('entry_types_organization_code_unique');
            $table->unique('code');
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('arrival_methods', function (Blueprint $table): void {
            $table->dropUnique('arrival_methods_organization_code_unique');
            $table->unique('code');
            $table->dropConstrainedForeignId('organization_id');
        });
        Schema::table('specialties', function (Blueprint $table): void {
            $table->dropUnique('specialties_organization_code_unique');
            $table->unique('code');
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
