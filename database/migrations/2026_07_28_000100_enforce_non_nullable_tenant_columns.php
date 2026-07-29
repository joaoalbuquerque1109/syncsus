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
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
        Schema::table('health_professionals', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('health_professionals', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
        Schema::table('patients', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id')->nullable()->change();
        });
    }
};
