<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->index(
                ['health_unit_id', 'current_status', 'closed_at'],
                'encounters_unit_status_closed_at_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('encounters', function (Blueprint $table): void {
            $table->dropIndex('encounters_unit_status_closed_at_index');
        });
    }
};
