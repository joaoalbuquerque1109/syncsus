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
        Schema::table('laboratory_exams', function (Blueprint $table): void {
            $table->string('sus_procedure_code', 10)->nullable()->change();
        });
        Schema::table('laboratory_order_transmissions', function (Blueprint $table): void {
            $table->string('status', 32)->default('awaiting_configuration')->change();
        });

        DB::table('laboratory_integrations')->update(['result_sync_enabled' => false]);
        DB::table('laboratory_order_transmissions')
            ->where('status', 'awaiting_contract')
            ->update(['status' => 'awaiting_configuration']);
    }

    public function down(): void
    {
        DB::table('laboratory_order_transmissions')
            ->where('status', 'awaiting_configuration')
            ->update(['status' => 'awaiting_contract']);
        Schema::table('laboratory_order_transmissions', function (Blueprint $table): void {
            $table->string('status', 32)->default('awaiting_contract')->change();
        });

        Schema::table('laboratory_exams', function (Blueprint $table): void {
            $table->string('sus_procedure_code', 32)->nullable()->change();
        });
    }
};
