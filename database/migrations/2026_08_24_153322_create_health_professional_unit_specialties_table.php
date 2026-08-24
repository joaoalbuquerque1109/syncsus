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
            // Nomes de constraint explicitos e curtos: o nome default do Laravel
            // (tabela_coluna_foreign) passa de 64 caracteres para esta tabela e
            // o MySQL rejeita a migration (SQLite, usado nos testes, nao tem
            // esse limite - por isso so quebrou em producao).
            $table->foreignId('health_professional_id')
                ->constrained(indexName: 'hpus_professional_fk')->cascadeOnDelete();
            $table->foreignId('health_unit_id')
                ->constrained(indexName: 'hpus_unit_fk')->cascadeOnDelete();
            $table->foreignId('specialty_id')
                ->constrained(indexName: 'hpus_specialty_fk')->restrictOnDelete();
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
