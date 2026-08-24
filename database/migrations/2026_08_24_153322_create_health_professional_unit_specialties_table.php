<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_professional_unit_specialties', function (Blueprint $table): void {
            $table->foreignId('health_professional_id')->constrained()->cascadeOnDelete();
            $table->foreignId('health_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('specialty_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->primary(
                ['health_professional_id', 'health_unit_id', 'specialty_id'],
                'professional_unit_specialty_primary',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_professional_unit_specialties');
    }
};
