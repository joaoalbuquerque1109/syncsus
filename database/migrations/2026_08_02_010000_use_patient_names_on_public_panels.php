<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('panels')->update(['identification_mode' => 'full_name']);
        DB::table('panels')
            ->where('institutional_message', 'Aguarde sua senha e dirija-se ao local indicado.')
            ->update([
                'institutional_message' => 'Aguarde a chamada pelo seu nome e dirija-se ao local indicado.',
            ]);
    }

    public function down(): void
    {
        DB::table('panels')->update(['identification_mode' => 'ticket_only']);
        DB::table('panels')
            ->where('institutional_message', 'Aguarde a chamada pelo seu nome e dirija-se ao local indicado.')
            ->update([
                'institutional_message' => 'Aguarde sua senha e dirija-se ao local indicado.',
            ]);
    }
};
