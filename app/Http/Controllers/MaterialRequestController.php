<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\MaterialRequest;
use Illuminate\Http\Request;

class MaterialRequestController extends Controller
{
    // ══════════════════════════════════════════════════════
    //  Student: Submit Regular Material Requests
    // ══════════════════════════════════════════════════════

    /**
     * POST /student/material-requests/regular
     * Body: { "courses": [1, 2, 3] }
     */
    public function storeRegular(Request $request)
    {
        $request->validate([
            'courses'   => 'required|array|min:1',
            'courses.*' => 'required|integer|exists:courses,id',
        ]);

        $student = auth()->user();

        $created = [];
        $skipped = [];

        foreach ($request->courses as $courseId) {
            $course = Course::find($courseId);

            // 1. Prevent duplicate pending/approved requests for same course
            $exists = MaterialRequest::where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->where('type', 'regular')
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($exists) {
                $skipped[] = [
                    'course_id' => $courseId,
                    'reason'    => 'You already have a pending or approved request for this course.',
                ];
                continue;
            }

            // 2. Check if student already passed this course
            $alreadyPassed = \App\Models\StudentCourses::where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->where('is_passed', true)
                ->exists();

            if ($alreadyPassed) {
                $skipped[] = [
                    'course_id' => $courseId,
                    'reason'    => 'You have already passed this course.',
                ];
                continue;
            }

            // 3. Check prerequisite
            // if (!empty($course->prerequisite)) {
            //     $prereqCode = trim($course->prerequisite);
            //     $prereqCourse = Course::where('code', $prereqCode)->first();
                
            //     if ($prereqCourse) {
            //         $passedPrereq = \App\Models\StudentCourses::where('student_id', $student->id)
            //             ->where('course_id', $prereqCourse->id)
            //             ->where('is_passed', true)
            //             ->exists();

            //         if (!$passedPrereq) {
            //             $skipped[] = [
            //                 'course_id' => $courseId,
            //                 'reason'    => "You must pass the prerequisite course ({$prereqCourse->name}) first.",
            //             ];
            //             continue;
            //         }
            //     }
            // }

            $created[] = MaterialRequest::create([
                'student_id'     => $student->id,
                'course_id'      => $courseId,
                'type'           => 'regular',
                'status'         => 'pending',
                'student_notes'  => null,
                'adviser_notes'  => null,
            ]);
        }

        $data = [
            'created' => $created,
        ];

        if (!empty($skipped)) {
            $data['skipped_courses'] = $skipped;
        }

        return $this->created($data, 'Regular material requests processed.');
    }

    // ══════════════════════════════════════════════════════
    //  Student: Submit Graduation Material Requests
    // ══════════════════════════════════════════════════════

    /**
     * POST /student/material-requests/graduation
     * Body: { "courses": [1, 2], "student_notes": "I need these for graduation." }
     */
    public function storeGraduation(Request $request)
    {
        $request->validate([
            'courses'        => 'required|array|min:1',
            'courses.*'      => 'required|integer|exists:courses,id',
            'student_notes'  => 'required|string|max:1000',
        ]);

        $student = auth()->user();

        $created = [];
        $skipped = [];

        foreach ($request->courses as $courseId) {
            // Prevent duplicate pending/approved requests for same course
            $exists = MaterialRequest::where('student_id', $student->id)
                ->where('course_id', $courseId)
                ->where('type', 'graduation')
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($exists) {
                $skipped[] = $courseId;
                continue;
            }

            $created[] = MaterialRequest::create([
                'student_id'     => $student->id,
                'course_id'      => $courseId,
                'type'           => 'graduation',
                'status'         => 'pending',
                'student_notes'  => $request->student_notes,
                'adviser_notes'  => null,
            ]);
        }

        $data = [
            'created' => $created,
        ];

        if (!empty($skipped)) {
            $data['skipped_course_ids'] = $skipped;
            $data['skip_reason'] = 'A pending or approved graduation request already exists for these courses.';
        }

        return $this->created($data, 'Graduation material requests submitted successfully.');
    }

    // ══════════════════════════════════════════════════════
    //  Student: View My Requests (with status filter)
    // ══════════════════════════════════════════════════════

    /**
     * GET /student/material-requests
     * Query params: ?status=pending|approved|rejected  ?type=regular|graduation  ?per_page=10
     */
    public function myRequests(Request $request)
    {
        $request->validate([
            'status'   => 'nullable|in:pending,approved,rejected',
            'type'     => 'nullable|in:regular,graduation',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $student  = auth()->user();
        $per_page = $request->per_page ?? 10;

        $query = MaterialRequest::with('course')
            ->where('student_id', $student->id)
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $requests = $query->paginate($per_page);

        return $this->paginated($requests, 'requests');
    }
}
