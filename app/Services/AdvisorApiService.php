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

        // Gemini chain-of-thought takes ~30-90 seconds per student — set a generous timeout
        $response = Http::timeout(180)->post("{$this->baseUrl}/student/recommend", [
            'student_code' => $studentCode,
            'term'         => $term,
        ]);

        if ($response->status() === 404) {
            throw new \RuntimeException("Student '{$studentCode}' not found in advisor data.");
        }

        if ($response->status() === 503) {
            $msg = $response->json('detail.message') ?? 'Advisor not ready.';
            throw new \RuntimeException($msg);
        }

        if ($response->failed()) {
            $msg = $response->json('detail.message') ?? $response->body();
            Log::error('AdvisorApiService: recommend failed', [
                'student_code' => $studentCode,
                'response'     => $msg,
            ]);
            throw new \RuntimeException("Recommendation failed: {$msg}");
        }

        return $response->json('data', []);
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
