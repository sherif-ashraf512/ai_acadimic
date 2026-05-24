<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\StudentCourses;
use Illuminate\Http\Request;

class StudentCoursesController extends Controller
{
    // ══════════════════════════════════════════════════════
    //  1. Completed Courses (is_passed = true)
    // ══════════════════════════════════════════════════════

    /**
     * GET /student/courses/completed
     * Returns all courses the student has passed.
     */
    public function completed(Request $request)
    {
        $student = auth()->user();

        $completedCourseIds = StudentCourses::where('student_id', $student->id)
            ->where('is_passed', true)
            ->pluck('course_id');

        $courses = Course::whereIn('id', $completedCourseIds)
            ->orderBy('name')
            ->get();

        return $this->success([
            'count'   => $courses->count(),
            'courses' => $courses,
        ], 'Completed courses fetched successfully.');
    }

    // ══════════════════════════════════════════════════════
    //  2. Remaining Courses (not enrolled at all)
    // ══════════════════════════════════════════════════════

    /**
     * GET /student/courses/remaining
     * Returns all courses the student has never been enrolled in
     * (neither passed nor currently enrolled).
     */
    public function remaining(Request $request)
    {
        $student = auth()->user();

        // All course IDs the student has any record for
        $enrolledCourseIds = StudentCourses::where('student_id', $student->id)
            ->pluck('course_id');

        $courses = Course::whereNotIn('id', $enrolledCourseIds)
            ->orderBy('name')
            ->get();

        return $this->success([
            'count'   => $courses->count(),
            'courses' => $courses,
        ], 'Remaining (not yet enrolled) courses fetched successfully.');
    }

    // ══════════════════════════════════════════════════════
    //  3. Currently Enrolled Courses (is_passed = false)
    // ══════════════════════════════════════════════════════

    /**
     * GET /student/courses/enrolled
     * Returns courses the student is currently registered in but hasn't passed yet.
     */
    public function enrolled(Request $request)
    {
        $student = auth()->user();

        $enrollments = StudentCourses::with('course')
            ->where('student_id', $student->id)
            ->where('is_passed', false)
            ->orderBy('created_at', 'desc')
            ->get();

        $courses = $enrollments->map(fn($e) => $e->course)->filter()->values();

        return $this->success([
            'count'   => $courses->count(),
            'courses' => $courses,
        ], 'Currently enrolled courses fetched successfully.');
    }

    // ══════════════════════════════════════════════════════
    //  4. All My Courses (summary)
    // ══════════════════════════════════════════════════════

    /**
     * GET /student/courses
     * Returns a summary of all the student's courses grouped by status.
     */
    public function myCourses(Request $request)
    {
        $student = auth()->user();

        $allEnrollments = StudentCourses::with('course')
            ->where('student_id', $student->id)
            ->get();

        $completedCourseIds = $allEnrollments->where('is_passed', true)->pluck('course_id');
        $enrolledCourseIds  = $allEnrollments->pluck('course_id');

        $completed = $allEnrollments
            ->where('is_passed', true)
            ->map(fn($e) => $e->course)
            ->filter()
            ->values();

        $inProgress = $allEnrollments
            ->where('is_passed', false)
            ->map(fn($e) => $e->course)
            ->filter()
            ->values();

        $remaining = Course::whereNotIn('id', $enrolledCourseIds)
            ->orderBy('name')
            ->get();

        return $this->success([
            'summary' => [
                'completed_count'  => $completed->count(),
                'in_progress_count' => $inProgress->count(),
                'remaining_count'  => $remaining->count(),
                'total_courses'    => $completed->count() + $inProgress->count() + $remaining->count(),
            ],
            'completed'   => $completed,
            'in_progress' => $inProgress,
            'remaining'   => $remaining,
        ], 'Student courses summary fetched successfully.');
    }
}
