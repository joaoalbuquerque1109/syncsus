<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_order_transmissions', function (Blueprint $table): void {
            $table->timestamp('sending_started_at')->nullable()->after('last_attempt_at');
            $table->timestamp('lease_expires_at')->nullable()->after('sending_started_at')->index();
            $table->string('worker_token', 64)->nullable()->after('lease_expires_at');
            $table->longText('request_payload')->nullable()->after('request_hash');
        });

        Schema::table('laboratory_transmission_attempts', function (Blueprint $table): void {
            $table->longText('response_payload')->nullable()->after('response_hash');
        });
    }

    public function down(): void
    {
        Schema::table('laboratory_transmission_attempts', function (Blueprint $table): void {
            $table->dropColumn('response_payload');
        });

        Schema::table('laboratory_order_transmissions', function (Blueprint $table): void {
            $table->dropIndex(['lease_expires_at']);
            $table->dropColumn([
                'sending_started_at',
                'lease_expires_at',
                'worker_token',
                'request_payload',
            ]);
        });
    }
};
