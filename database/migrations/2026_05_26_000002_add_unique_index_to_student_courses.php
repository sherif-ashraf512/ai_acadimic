<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a unique composite index on (student_id, course_id) to student_courses.
 * This is required for DB::upsert() to work correctly when the AI job
 * inserts/updates rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_courses', function (Blueprint $table) {
            // Remove any existing duplicate rows first (safety guard)
            // then add the unique constraint
            $table->unique(['student_id', 'course_id'], 'student_courses_student_course_unique');
        });
    }

    public function down(): void
    {
        Schema::table('student_courses', function (Blueprint $table) {
            $table->dropUnique('student_courses_student_course_unique');
        });
    }
};
