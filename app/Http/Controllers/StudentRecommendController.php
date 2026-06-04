<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\User;
use App\Services\AdvisorApiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * StudentRecommendController
 *
 * Bridges the Laravel student context → Python Advisor API → client response.
 *
 * After the Python API returns recommended course codes, we resolve each code
 * against our local `courses` DB table and return full Course model objects
 * (same shape as the rest of the system), enriched with AI justification data.
 *
 * Routes:
 *   POST /api/student/recommend
 *   GET  /api/student/recommend/status
 */
class StudentRecommendController extends Controller
{
    public function __construct(private readonly AdvisorApiService $advisor) {}

    // ══════════════════════════════════════════════════════════════════════════
    //  POST /api/student/recommend
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Return recommended courses for the authenticated student.
     *
     * Body (JSON, optional):
     *   term — target semester string (default: "Next Term")
     */
    public function recommend(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role !== 'student') {
            return $this->error('Only students can request recommendations.', 403);
        }

        if (!$user->code) {
            return $this->error('Your account does not have a student code assigned.', 422);
        }

        $term = $request->input('term', 'Next Term');

        // ── Check advisor availability first (fast, avoids hanging on 503) ──
        if (!$this->advisor->isAlive()) {
            return $this->error(
                'The recommendation service is currently unavailable. Please try again later.',
                503
            );
        }

        try {
            $data = $this->advisor->recommend($user->code, $term);
        } catch (\RuntimeException $e) {
            Log::warning('StudentRecommendController: recommend failed', [
                'student_code' => $user->code,
                'error'        => $e->getMessage(),
            ]);

            if (str_contains($e->getMessage(), 'not found')) {
                return $this->error(
                    'Your transcript data was not found in the advisor system. '
                    . 'Please contact an administrator to ensure your data has been set up.',
                    404
                );
            }

            if (str_contains($e->getMessage(), 'not ready') || str_contains($e->getMessage(), 'status:')) {
                return $this->error(
                    'The recommendation service is still being set up. Please try again in a few minutes.',
                    503
                );
            }

            return $this->error('Could not generate recommendations: ' . $e->getMessage(), 500);
        }

        // ── Resolve course codes against our DB ──────────────────────────────
        $rawCourses    = $data['recommended_courses'] ?? [];
        $resolvedCourses = $this->resolveCoursesFromDb($rawCourses);

        return $this->success([
            'student_code'        => $data['student_code']  ?? $user->code,
            'student_label'       => $data['student_label'] ?? null,
            'term'                => $data['term']          ?? $term,
            'count'               => count($resolvedCourses),
            'recommended_courses' => $resolvedCourses,
        ], 'Recommendations generated successfully.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  GET /api/student/recommend/status
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Let the frontend know whether the advisor is ready to serve recommendations.
     */
    public function status()
    {
        if (!$this->advisor->isAlive()) {
            return $this->success([
                'advisor_status'  => 'offline',
                'students_loaded' => 0,
                'message'         => 'Recommendation service is offline.',
            ], 'Status fetched.');
        }

        try {
            $status = $this->advisor->getSetupStatus();
        } catch (\Throwable $e) {
            return $this->error('Could not reach recommendation service: ' . $e->getMessage(), 503);
        }

        return $this->success($status, 'Advisor status fetched.');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Given a list of course objects from the Python API, fetch the matching
     * Course models from the DB (by `code`) and merge the AI-specific fields
     * (justification, catalog_availability_proof) into each DB record.
     *
     * Courses whose code is not found in the DB are returned as a plain object
     * built from the Python data so the response is never empty.
     *
     * @param  array  $rawCourses  Array of course objects from Python API.
     * @return array  Array of enriched course objects.
     */
    private function resolveCoursesFromDb(array $rawCourses): array
    {
        if (empty($rawCourses)) {
            return [];
        }

        // Collect all course codes in one query
        $codes = array_filter(array_map(
            fn($c) => trim($c['course_code'] ?? ''),
            $rawCourses
        ));

        // Load matching DB courses indexed by their code for O(1) lookup
        $dbCourses = Course::whereIn('code', $codes)
            ->get()
            ->keyBy('code');

        $resolved = [];

        foreach ($rawCourses as $raw) {
            $code      = trim($raw['course_code'] ?? '');
            $dbCourse  = $dbCourses->get($code);

            if ($dbCourse) {
                // Return the full DB Course model as array,
                // then append the AI-specific fields that only Python knows.
                $entry = $dbCourse->toArray();
                $entry['justification']              = $raw['justification']              ?? null;
                $entry['catalog_availability_proof'] = $raw['catalog_availability_proof'] ?? null;
            } else {
                // Course code not in DB yet — fallback to Python data so the
                // response is still useful. Log for debugging.
                Log::warning('StudentRecommendController: course code not found in DB', [
                    'course_code' => $code,
                ]);

                $entry = [
                    'id'                         => null,
                    'code'                       => $code,
                    'name'                       => $raw['course_title']          ?? $code,
                    'credit_hours'               => $raw['credits']               ?? null,
                    'prerequisite'               => null,
                    'justification'              => $raw['justification']         ?? null,
                    'catalog_availability_proof' => $raw['catalog_availability_proof'] ?? null,
                    '_source'                    => 'python_only', // flag: not in DB
                ];
            }

            $resolved[] = $entry;
        }

        return $resolved;
    }
}

