<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('legal_name');
            $table->string('trade_name');
            $table->string('document_number', 32)->nullable();
            $table->string('cnes_code', 16)->nullable();
            $table->string('timezone', 64)->default('America/Fortaleza');
            $table->string('locale', 10)->default('pt_BR');
            $table->string('logo_path')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('health_units', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('cnes_code', 16)->nullable();
            $table->string('postal_code', 16)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('city')->nullable();
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->string('street_number', 32)->nullable();
            $table->string('address_complement')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_units');
        Schema::dropIfExists('organizations');
    }
};
