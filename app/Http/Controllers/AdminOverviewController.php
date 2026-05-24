<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\MaterialRequest;
use App\Models\StudentCourses;
use App\Models\User;
use Illuminate\Http\Request;

class AdminOverviewController extends Controller
{
    /**
     * GET /api/admin/overview
     *
     * Returns a high-level dashboard snapshot for the admin:
     *   - Student counts (total, per level)
     *   - Course counts
     *   - Material request stats (pending, approved, rejected, by type)
     *   - Recent pending requests
     */
    public function index()
    {
        // ── Students ────────────────────────────────────────────────
        $totalStudents = User::where('role', 'student')->count();

        $studentsByLevel = User::where('role', 'student')
            ->selectRaw('level, COUNT(*) as count')
            ->groupBy('level')
            ->orderBy('level')
            ->get()
            ->mapWithKeys(fn($row) => ["level_{$row->level}" => $row->count]);

        // ── Courses ─────────────────────────────────────────────────
        $totalCourses = Course::count();

        // ── Material Requests ────────────────────────────────────────
        $requestStats = MaterialRequest::selectRaw('
                status,
                type,
                COUNT(*) as count
            ')
            ->groupBy('status', 'type')
            ->get();

        // Build a structured breakdown
        $requests = [
            'total'   => 0,
            'pending' => [
                'total'      => 0,
                'regular'    => 0,
                'graduation' => 0,
            ],
            'approved' => [
                'total'      => 0,
                'regular'    => 0,
                'graduation' => 0,
            ],
            'rejected' => [
                'total'      => 0,
                'regular'    => 0,
                'graduation' => 0,
            ],
        ];

        foreach ($requestStats as $row) {
            $requests[$row->status][$row->type] = $row->count;
            $requests[$row->status]['total']    += $row->count;
            $requests['total']                  += $row->count;
        }

        // ── Recent Pending Requests ──────────────────────────────────
        $recentPending = MaterialRequest::with(['student:id,name,code,level', 'course:id,name,code'])
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(fn($r) => [
                'id'            => $r->id,
                'type'          => $r->type,
                'student_notes' => $r->student_notes,
                'created_at'    => $r->created_at,
                'student'       => $r->student,
                'course'        => $r->course,
            ]);

        // ── Student Course Stats ─────────────────────────────────────
        $totalEnrollments = StudentCourses::count();
        $passedCourses    = StudentCourses::where('is_passed', true)->count();
        $inProgressCourses = StudentCourses::where('is_passed', false)->count();

        // ── Compose Response ─────────────────────────────────────────
        return $this->success([
            'students' => [
                'total'       => $totalStudents,
                'by_level'    => $studentsByLevel,
            ],
            'courses' => [
                'total'       => $totalCourses,
            ],
            'enrollments' => [
                'total'       => $totalEnrollments,
                'passed'      => $passedCourses,
                'in_progress' => $inProgressCourses,
            ],
            'material_requests' => $requests,
            'recent_pending_requests' => $recentPending,
        ], 'Admin overview fetched successfully.');
    }
}
