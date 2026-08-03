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
        Schema::table('exam_orders', function (Blueprint $table): void {
            $table->foreignId('medical_consultation_id')->nullable()->change();
            $table->foreignId('organization_id')->nullable()->after('public_id')->constrained()->restrictOnDelete();
            $table->foreignId('health_unit_id')->nullable()->after('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->after('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('origin', 24)->default('medical')->after('created_by')->index();
            $table->string('status', 24)->default('pending')->after('origin')->index();
            $table->timestamp('cancelled_at')->nullable()->after('requested_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
            $table->index(['health_unit_id', 'status', 'requested_at'], 'exam_orders_unit_status_date_index');
        });

        DB::table('exam_orders')->orderBy('id')->each(function (object $order): void {
            $encounter = DB::table('encounters')->where('id', $order->encounter_id)->first();
            if ($encounter === null) {
                return;
            }
            $unit = DB::table('health_units')->where('id', $encounter->health_unit_id)->first();
            DB::table('exam_orders')->where('id', $order->id)->update([
                'organization_id' => $unit?->organization_id,
                'health_unit_id' => $encounter->health_unit_id,
                'created_by' => $order->requested_by,
            ]);
        });

        Schema::table('exam_orders', function (Blueprint $table): void {
            $table->foreignId('organization_id')->nullable(false)->change();
            $table->foreignId('health_unit_id')->nullable(false)->change();
            $table->foreignId('created_by')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('exam_orders', function (Blueprint $table): void {
            $table->dropIndex('exam_orders_unit_status_date_index');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('health_unit_id');
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['origin', 'status', 'cancelled_at', 'cancellation_reason']);
            $table->foreignId('medical_consultation_id')->nullable(false)->change();
        });
    }
};
