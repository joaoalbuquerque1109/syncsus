<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laboratory_result_ingestions', function (Blueprint $table): void {
            $table->string('result_pdf_disk', 32)->nullable()->after('payload');
            $table->string('result_pdf_path', 191)->nullable()->after('result_pdf_disk');
            $table->char('result_pdf_hash', 64)->nullable()->after('result_pdf_path');
            $table->unsignedInteger('result_pdf_size')->nullable()->after('result_pdf_hash');
            $table->string('result_pdf_original_filename', 191)->nullable()->after('result_pdf_size');
        });

        Schema::table('exam_results', function (Blueprint $table): void {
            $table->string('result_pdf_disk', 32)->nullable()->after('content_hash');
            $table->string('result_pdf_path', 191)->nullable()->after('result_pdf_disk');
            $table->char('result_pdf_hash', 64)->nullable()->after('result_pdf_path');
            $table->unsignedInteger('result_pdf_size')->nullable()->after('result_pdf_hash');
            $table->string('result_pdf_original_filename', 191)->nullable()->after('result_pdf_size');
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table): void {
            $table->dropColumn([
                'result_pdf_disk', 'result_pdf_path', 'result_pdf_hash',
                'result_pdf_size', 'result_pdf_original_filename',
            ]);
        });

        Schema::table('laboratory_result_ingestions', function (Blueprint $table): void {
            $table->dropColumn([
                'result_pdf_disk', 'result_pdf_path', 'result_pdf_hash',
                'result_pdf_size', 'result_pdf_original_filename',
            ]);
        });
    }
};
