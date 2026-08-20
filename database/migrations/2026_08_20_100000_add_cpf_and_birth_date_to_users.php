<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('cpf', 11)->nullable()->after('email');
            $table->date('birth_date')->nullable()->after('cpf');
            $table->unique(['organization_id', 'cpf'], 'users_organization_cpf_unique');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_organization_cpf_unique');
            $table->dropColumn(['cpf', 'birth_date']);
        });
    }
};
