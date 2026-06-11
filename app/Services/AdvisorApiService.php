<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AdvisorApiService
 *
 * Thin HTTP client that talks to the Python FastAPI advisor server.
 * Base URL is controlled by the ADVISOR_API_URL env variable (default: http://127.0.0.1:8001).
 */
class AdvisorApiService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.advisor_api.url', 'http://127.0.0.1:8001'), '/');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  POST /setup
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Forward the uploaded catalog PDF and student Excel to the Python advisor API.
     * Processing is async on the Python side; poll getSetupStatus() for progress.
     *
     * @param  string  $pdfAbsPath    Absolute path to the PDF on disk.
     * @param  string  $excelAbsPath  Absolute path to the Excel on disk.
     * @param  string  $term          Target academic term string.
     * @return array   Decoded JSON response body.
     *
     * @throws \RuntimeException on connection or non-2xx HTTP error.
     */
    public function sendSetup(string $pdfAbsPath, string $excelAbsPath, string $term = 'Next Term'): array
    {
        Log::info('AdvisorApiService: sending setup files', [
            'pdf'   => basename($pdfAbsPath),
            'excel' => basename($excelAbsPath),
            'term'  => $term,
        ]);

        $response = Http::timeout(60)
            ->attach('collage_list',    file_get_contents($pdfAbsPath),   basename($pdfAbsPath))
            ->attach('student_formula', file_get_contents($excelAbsPath), basename($excelAbsPath))
            ->post("{$this->baseUrl}/setup", ['term' => $term]);

        if ($response->failed()) {
            $msg = $response->json('detail.message') ?? $response->body();
            Log::error('AdvisorApiService: setup request failed', ['response' => $msg]);
            throw new \RuntimeException("Advisor API setup failed: {$msg}");
        }

        return $response->json();
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  GET /setup/status
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Poll the Python advisor for its current setup status.
     *
     * @return array  e.g. ["status" => "ready", "students_loaded" => 42, ...]
     *
     * @throws \RuntimeException on connection failure.
     */
    public function getSetupStatus(): array
    {
        $response = Http::timeout(10)->get("{$this->baseUrl}/setup/status");

        if ($response->failed()) {
            $msg = $response->json('detail.message') ?? $response->body();
            throw new \RuntimeException("Advisor API status check failed: {$msg}");
        }

        return $response->json('data', []);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  POST /student/recommend
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Request course recommendations for a student by their code.
     *
     * @param  string  $studentCode  The student's code (matches User.code).
     * @param  string  $term         Target academic term.
     * @return array   The 'data' portion of the API response.
     *
     * @throws \RuntimeException on connection or API error.
     */
    public function recommend(string $studentCode, string $term = 'Next Term'): array
    {
        Log::info('AdvisorApiService: requesting recommendations', [
            'student_code' => $studentCode,
            'term'         => $term,
        ]);

        // ── Step 1: Start async task ────────────────────────────────────────
        $startResponse = Http::timeout(30)->post("{$this->baseUrl}/student/recommend", [
            'student_code' => $studentCode,
            'term'         => $term,
        ]);

        if ($startResponse->status() === 404) {
            throw new \RuntimeException("Student '{$studentCode}' not found in advisor data.");
        }

        if ($startResponse->status() === 503) {
            $msg = $startResponse->json('detail.message') ?? 'Advisor not ready.';
            throw new \RuntimeException($msg);
        }

        if ($startResponse->failed()) {
            $msg = $startResponse->json('detail.message') ?? $startResponse->body();
            Log::error('AdvisorApiService: recommend start failed', [
                'student_code' => $studentCode,
                'response'     => $msg,
            ]);
            throw new \RuntimeException("Recommendation failed: {$msg}");
        }

        $taskId = $startResponse->json('data.task_id');
        if (!$taskId) {
            throw new \RuntimeException('Advisor API did not return a task_id.');
        }

        Log::info("AdvisorApiService: task started", ['task_id' => $taskId]);

        // ── Step 2: Poll for result ─────────────────────────────────────────
        $maxPolls    = 60;       // 60 × 5s = 5 minutes max
        $pollDelay   = 5;        // seconds between polls

        for ($i = 0; $i < $maxPolls; $i++) {
            sleep($pollDelay);

            $pollResponse = Http::timeout(15)
                ->get("{$this->baseUrl}/student/recommend/status/{$taskId}");

            if ($pollResponse->failed()) {
                $msg = $pollResponse->json('detail.message') ?? $pollResponse->body();

                // 500 = task failed with error
                if ($pollResponse->status() === 500) {
                    throw new \RuntimeException("Recommendation failed: {$msg}");
                }

                // 404 = task not found (shouldn't happen)
                if ($pollResponse->status() === 404) {
                    throw new \RuntimeException("Recommendation task lost. Please retry.");
                }

                Log::warning('AdvisorApiService: poll error', ['response' => $msg]);
                continue;
            }

            $status = $pollResponse->json('data.status', 'processing');

            if ($status === 'processing') {
                continue;  // still working, poll again
            }

            // Done — return the result
            return $pollResponse->json('data', []);
        }

        throw new \RuntimeException(
            "Recommendation timed out after " . ($maxPolls * $pollDelay) . " seconds. Please try again."
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  GET /health
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Quick health-check; returns true if the Python server is reachable.
     */
    public function isAlive(): bool
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/health");
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
