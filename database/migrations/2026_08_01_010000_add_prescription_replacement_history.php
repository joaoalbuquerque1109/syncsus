<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->foreignId('parent_prescription_id')->nullable()->constrained('prescriptions')->restrictOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('replacement_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('replaced_by');
            $table->dropConstrainedForeignId('parent_prescription_id');
            $table->dropColumn(['replaced_at', 'replacement_reason']);
        });
    }
};
