<?php

namespace App\Jobs;

use App\Models\Course;
use App\Models\SetupFile;
use App\Models\StudentCourses;
use App\Models\User;
use App\Services\GeminiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ProcessSetupFilesJob
 *
 * Dispatched after an admin uploads the college catalog (PDF) and the student
 * transcript workbook (XLSX). Runs on the queue so the HTTP response returns
 * immediately.
 *
 * Steps performed:
 *  1. Mark setup_files row as "processing".
 *  2. Call GeminiService to extract courses from the PDF.
 *  3. Upsert all extracted courses into the `courses` table.
 *  4. Call GeminiService to extract students + passed courses from the XLSX.
 *  5. For each student:
 *     a. Resolve the student User by code.
 *     b. Resolve each passed course by code.
 *     c. Upsert a student_courses row with is_passed = true.
 *  6. Mark the setup_files row as "completed" (or "failed" on error).
 */
class ProcessSetupFilesJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    // Retry up to 3 times — extra attempt for 429 rate-limit recovery
    public int $tries   = 3;
    public int $timeout = 600; // 10 minutes — accommodates sleep() on 429 + large files

    // ── Constructor ────────────────────────────────────────────────────────────

    public function __construct(private readonly int $setupFileId) {}

    // ══════════════════════════════════════════════════════════════════════════
    //  Handle
    // ══════════════════════════════════════════════════════════════════════════

    public function handle(GeminiService $gemini): void
    {
        $setup = SetupFile::findOrFail($this->setupFileId);

        // ── Mark as processing ──────────────────────────────────────────────
        $setup->update(['processing_status' => 'processing', 'processing_error' => null]);

        try {
            // ── Resolve absolute paths ──────────────────────────────────────
            // The model accessor returns a full URL; we need the raw disk path.
            // realpath() normalises mixed separators on Windows (\ vs /)
            // and returns false when the file doesn't exist, so we can fail fast.
            $pdfRelative   = $setup->getRawOriginal('collage_list');
            $excelRelative = $setup->getRawOriginal('student_formula');

            $pdfPath   = realpath(Storage::disk('public')->path($pdfRelative));
            $excelPath = realpath(Storage::disk('public')->path($excelRelative));

            if (!$pdfPath || !file_exists($pdfPath)) {
                throw new \RuntimeException("PDF file not found on disk: {$pdfRelative}");
            }

            if (!$excelPath || !file_exists($excelPath)) {
                throw new \RuntimeException("Excel file not found on disk: {$excelRelative}");
            }

            // ── Step 1: Extract & persist courses ──────────────────────────
            Log::info('ProcessSetupFilesJob: extracting courses from PDF', ['setup_id' => $setup->id]);
            $courses = $gemini->extractCoursesFromPdf($pdfPath);
            $this->persistCourses($courses);
            Log::info('ProcessSetupFilesJob: courses persisted', ['count' => count($courses)]);

            // ── Step 2: Extract & persist students (progressively) ──────────
            Log::info('ProcessSetupFilesJob: extracting students from Excel', ['setup_id' => $setup->id]);

            $studentCount = 0;

            // The callback is fired immediately after each student sheet is parsed,
            // so students are saved to the DB one-by-one — even if a later sheet fails.
            $gemini->extractStudentsFromExcel($excelPath, function (array $student) use (&$studentCount) {
                $this->persistStudentCourses([$student]);
                $studentCount++;
                Log::info('ProcessSetupFilesJob: student persisted', [
                    'student_code' => $student['student_code'],
                    'courses'      => count($student['courses'] ?? []),
                    'saved'        => $studentCount,
                ]);
            });

            Log::info('ProcessSetupFilesJob: student courses persisted', ['count' => $studentCount]);

            // ── Done ───────────────────────────────────────────────────────
            $setup->update([
                'processing_status' => 'completed',
                'processed_at'      => now(),
                'processing_error'  => null,
            ]);

            Log::info('ProcessSetupFilesJob: completed successfully', ['setup_id' => $setup->id]);

        } catch (Throwable $e) {
            Log::error('ProcessSetupFilesJob: failed', [
                'setup_id' => $setup->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            $setup->update([
                'processing_status' => 'failed',
                'processing_error'  => $this->sanitizeErrorMessage($e->getMessage()),
            ]);

            // Re-throw so the queue marks it as failed and retries if attempts remain
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Upsert extracted courses into the `courses` table.
     * Uses `code` as the unique key; updates name, credit_hours, prerequisite.
     */
    private function persistCourses(array $courses): void
    {
        if (empty($courses)) {
            return;
        }

        $now   = now();
        $chunk = [];

        foreach ($courses as $c) {
            $chunk[] = [
                'code'         => $c['code'],
                'name'         => $c['name'],
                'credit_hours' => $c['credit_hours'],
                'prerequisite' => $c['prerequisite'],
                'created_at'   => $now,
                'updated_at'   => $now,
            ];
        }

        // Process in chunks of 200 to avoid hitting DB parameter limits
        foreach (array_chunk($chunk, 200) as $batch) {
            DB::table('courses')->upsert(
                $batch,
                ['code'],                                          // unique key
                ['name', 'credit_hours', 'prerequisite', 'updated_at'] // columns to update
            );
        }
    }

    /**
     * For each student in the extracted list:
     *  - Resolve the User by student_code.
     *  - Resolve each passed Course by code.
     *  - Upsert student_courses rows (is_passed = true).
     */
    private function persistStudentCourses(array $students): void
    {
        if (empty($students)) {
            Log::warning('ProcessSetupFilesJob: persistStudentCourses called with empty students array');
            return;
        }

        // Pre-load all courses for fast lookup — three strategies:
        //   1. Exact code match:        "HM111"
        //   2. Exact full name match:   "لغة إنجليزية 1 English 1"
        //   3. Arabic-only name match:  "لغة إنجليزية 1"
        //      (for Excel files that only contain the Arabic part of bilingual names)
        $courseByCode = Course::pluck('id', 'code')->toArray();
        $courseByName = Course::pluck('id', 'name')->toArray();

        // Build Arabic-only lookup: strip trailing English text from bilingual names
        $courseByArabicName = [];
        foreach (Course::select('id', 'name')->get() as $course) {
            // Keep only the leading Arabic portion (everything before ASCII letters start)
            $arabicPart = trim(preg_replace('/[A-Za-z].*$/u', '', $course->name));
            $arabicPart = rtrim($arabicPart); // strip trailing spaces/digits after Arabic
            if (!empty($arabicPart) && !isset($courseByArabicName[$arabicPart])) {
                $courseByArabicName[$arabicPart] = $course->id;
            }
        }

        Log::info('ProcessSetupFilesJob: course lookup tables loaded', [
            'by_code'         => count($courseByCode),
            'by_full_name'    => count($courseByName),
            'by_arabic_name'  => count($courseByArabicName),
            'sample_arabic'   => array_slice(array_keys($courseByArabicName), 0, 3),
        ]);

        foreach ($students as $studentData) {
            $studentCode = $studentData['student_code'];
            $courseItems = $studentData['courses'] ?? [];

            Log::info('ProcessSetupFilesJob: processing student', [
                'student_code'  => $studentCode,
                'courses_count' => count($courseItems),
                'sample_course' => $courseItems[0] ?? null,
            ]);

            // Resolve student by code
            $student = User::where('code', $studentCode)->where('role', 'student')->first();

            if (!$student) {
                // Diagnostic: show what codes exist in DB
                $sampleDbCodes = User::where('role', 'student')->limit(5)->pluck('code')->toArray();
                Log::warning('ProcessSetupFilesJob: student not found', [
                    'gemini_code'    => $studentCode,
                    'db_sample_codes' => $sampleDbCodes,
                ]);
                continue;
            }

            $savedCount  = 0;
            $missedCount = 0;

            // Resolve and save ALL courses using the StudentCourses model
            foreach ($courseItems as $courseItem) {
                if (is_array($courseItem)) {
                    $identifier = trim((string) ($courseItem['name'] ?? ''));
                    $isPassed   = (bool) ($courseItem['is_passed'] ?? false);
                } else {
                    $identifier = trim((string) $courseItem);
                    $isPassed   = true;
                }

                if (empty($identifier)) {
                    continue;
                }

                // Try code first, then name
                $courseId = $courseByCode[$identifier]        // 1. exact code  (HM111)
                         ?? $courseByName[$identifier]        // 2. exact full name
                         ?? $courseByArabicName[$identifier]  // 3. Arabic-only portion
                         ?? null;

                if (!$courseId) {
                    $missedCount++;
                    if ($missedCount <= 3) { // only log first 3 misses per student
                        Log::warning('ProcessSetupFilesJob: course not found', [
                            'identifier' => $identifier,
                            'student'    => $studentCode,
                        ]);
                    }
                    continue;
                }

                // Use model updateOrCreate to handle duplicates safely
                StudentCourses::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'course_id'  => $courseId,
                    ],
                    [
                        'is_passed' => $isPassed,
                    ]
                );

                $savedCount++;
            }

            Log::info('ProcessSetupFilesJob: student courses saved', [
                'student_code' => $studentCode,
                'saved'        => $savedCount,
                'not_found'    => $missedCount,
                'total'        => count($courseItems),
            ]);
        }
    }

    /**
     * Upsert student_courses rows using the Eloquent model.
     * Kept for backward compatibility / bulk operations.
     */
    private function upsertStudentCourses(array $rows): void
    {
        StudentCourses::upsert(
            $rows,
            ['student_id', 'course_id'],   // unique composite key
            ['is_passed', 'updated_at']    // columns to update on conflict
        );
    }

    /**
     * Sanitize an error message so it can be safely stored in a MySQL utf8 column.
     *
     * - Strips non-ASCII characters (e.g. Arabic from AI responses) which cause
     *   "Incorrect string value" errors when the column charset is not utf8mb4.
     * - Truncates to 500 characters to avoid column length issues.
     */
    private function sanitizeErrorMessage(string $message): string
    {
        // Remove non-ASCII characters (keeps Latin, digits, punctuation)
        $ascii = preg_replace('/[^\x00-\x7F]+/', '?', $message);

        // Truncate to 500 chars with an indicator if cut
        if (strlen($ascii) > 500) {
            $ascii = substr($ascii, 0, 497) . '...';
        }

        return $ascii;
    }
}

