<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_databases', function (Blueprint $table): void {
            $table->string('provisioning_mode', 24)->default('legacy_migration')->after('database_name')->index();
            $table->string('infrastructure_status', 32)->default('pending')->after('schema_status')->index();
            $table->string('runtime_username', 32)->nullable()->unique()->after('infrastructure_status');
            $table->text('encrypted_runtime_password')->nullable()->after('runtime_username');
            $table->string('runtime_host', 255)->nullable()->after('encrypted_runtime_password');
            $table->foreignId('requested_by_user_id')->nullable()->after('runtime_host')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_databases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('requested_by_user_id');
            $table->dropUnique(['runtime_username']);
            $table->dropColumn([
                'provisioning_mode',
                'infrastructure_status',
                'runtime_username',
                'encrypted_runtime_password',
                'runtime_host',
            ]);
        });
    }
};
