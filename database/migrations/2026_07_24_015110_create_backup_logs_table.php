<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('status', 32)->index();
            $table->string('database_path')->nullable();
            $table->string('files_path')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
