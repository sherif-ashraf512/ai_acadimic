<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add AI processing status tracking columns to setup_files table.
 *
 * processing_status: pending | processing | completed | failed
 * processing_error:  last error message if status = 'failed'
 * processed_at:      timestamp when the job completed successfully
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('setup_files', function (Blueprint $table) {
            $table->enum('processing_status', ['pending', 'processing', 'completed', 'failed'])
                  ->default('pending')
                  ->after('student_formula');

            $table->text('processing_error')
                  ->nullable()
                  ->after('processing_status');

            $table->timestamp('processed_at')
                  ->nullable()
                  ->after('processing_error');
        });
    }

    public function down(): void
    {
        Schema::table('setup_files', function (Blueprint $table) {
            $table->dropColumn(['processing_status', 'processing_error', 'processed_at']);
        });
    }
};
