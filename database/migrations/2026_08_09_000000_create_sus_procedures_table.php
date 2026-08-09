<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sus_procedures', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();
            $table->string('description');
            $table->string('complexity', 8)->nullable();
            $table->string('sex_restriction', 4)->nullable();
            $table->unsignedInteger('minimum_age_months')->nullable();
            $table->unsignedInteger('maximum_age_months')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sus_procedures');
    }
};
