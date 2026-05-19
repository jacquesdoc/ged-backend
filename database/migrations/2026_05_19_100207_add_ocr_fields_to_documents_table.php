<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (!Schema::hasColumn('documents', 'ocr_text')) {
                $table->longText('ocr_text')->nullable()->after('description');
            }
            if (!Schema::hasColumn('documents', 'ocr_confidence')) {
                $table->float('ocr_confidence')->nullable()->after('ocr_text');
            }
            if (!Schema::hasColumn('documents', 'ocr_processed_at')) {
                $table->timestamp('ocr_processed_at')->nullable()->after('ocr_confidence');
            }
            if (!Schema::hasColumn('documents', 'ocr_status')) {
                $table->enum('ocr_status', ['pending', 'processing', 'done', 'failed', 'not_supported'])
                    ->default('pending')->after('ocr_processed_at');
            }
            if (!Schema::hasColumn('documents', 'metadata')) {
                $table->json('metadata')->nullable()->after('ocr_status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'ocr_text', 'ocr_confidence',
                'ocr_processed_at', 'ocr_status', 'metadata'
            ]);
        });
    }
};