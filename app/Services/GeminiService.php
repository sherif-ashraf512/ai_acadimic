<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory as SpreadsheetIOFactory;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * GeminiService
 *
 * Wraps the Google Gemini REST API (gemini-2.0-flash).
 *
 * Strategy change: instead of sending raw binary files (which consume huge
 * amounts of tokens when base64-encoded), we now:
 *  1. Extract plain text locally from the PDF / XLSX.
 *  2. Send that plain text to Gemini inside the prompt.
 *
 * This reduces token usage by ~70-80% and avoids "token limit exceeded" errors.
 */
class GeminiService
{
    // ── Constants ──────────────────────────────────────────────────────────────

    private const API_BASE   = 'https://generativelanguage.googleapis.com/v1beta/models';
    private const MODEL      = 'gemini-3.5-flash';
    private const MAX_TOKENS = 32768; // large enough for 100+ courses in JSON

    // Max characters of extracted text to send (to stay within context window)
    // gemini-2.5-flash supports ~1M tokens input, but we cap for safety
    private const MAX_TEXT_CHARS = 80000;

    // ── Constructor ────────────────────────────────────────────────────────────

    public function __construct(private readonly string $apiKey)
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY is not configured.');
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  1. Extract courses from PDF (college catalog)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Extract plain text from PDF locally, then ask Gemini to parse courses.
     *
     * @param  string $pdfPath  Absolute path to the stored PDF file.
     * @return array<int, array{code: string, name: string, credit_hours: int, prerequisite: string|null}>
     *
     * @throws RuntimeException on extraction or API failure.
     */
    public function extractCoursesFromPdf(string $pdfPath): array
    {
        Log::info('GeminiService: extracting text from PDF locally', ['path' => $pdfPath]);

        $text = $this->extractTextFromPdf($pdfPath);

        Log::info('GeminiService: PDF text extracted', [
            'chars' => strlen($text),
            'truncated' => strlen($text) > self::MAX_TEXT_CHARS,
        ]);

        // Truncate if needed (keep the beginning where courses are usually listed)
        $text = mb_substr($text, 0, self::MAX_TEXT_CHARS);

        $prompt = <<<PROMPT
You are an academic data extractor. Below is the raw extracted text from a university course catalog (لائحة الكلية).

IMPORTANT: Arabic text in this document was extracted from a PDF and may appear REVERSED (visual order instead of logical order). For example "ةيزيلجنإ ةغل" is actually "لغة إنجليزية" read backwards. Please read all reversed Arabic words correctly and use the correct Arabic form in your output.

The text may be messy with extra spaces, mixed Arabic/English, or irregular formatting — do your best to identify all courses.

Extract EVERY course and return ONLY a valid JSON array (no markdown, no explanation).
Each element must follow this exact schema:
{
  "code": "<course code>",
  "name": "<full course name>",
  "credit_hours": <integer>,
  "prerequisite": "<prerequisite course code or null>"
}

Rules:
- "code" must be the course code (e.g. "CS101", "MATH201"). If not present use null.
- "name" is the full Arabic or English course name as written.
- "credit_hours" must be an integer (convert text like "ثلاثة" → 3).
- "prerequisite" is the CODE of the required prerequisite course, or null if none.
- Do NOT include any text outside the JSON array.
- Do NOT include markdown code fences.

--- DOCUMENT TEXT START ---
{$text}
--- DOCUMENT TEXT END ---
PROMPT;

        $raw  = $this->callApi($this->buildTextPayload($prompt));
        $data = $this->parseJsonArray($raw, 'courses');

        return $this->normalizeCourses($data);
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  2. Extract students + completed courses from Excel
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Extract students from XLSX by processing each sheet individually.
     *
     * Each sheet = one student. Processing one sheet at a time keeps both
     * the input and output small, avoiding JSON truncation errors.
     *
     * @param  string        $excelPath  Absolute path to the stored XLSX file.
     * @param  callable|null $onStudent  Optional callback fired immediately after each
     *                                   successful student parse: fn(array $student): void
     *                                   Allows the caller to save progressively.
     * @return array<int, array{student_code: string, passed_course_codes: string[]}>
     *
     * @throws RuntimeException on file load failure.
     */
    public function extractStudentsFromExcel(string $excelPath, ?callable $onStudent = null): array
    {
        Log::info('GeminiService: loading Excel file', ['path' => $excelPath]);

        try {
            $spreadsheet = SpreadsheetIOFactory::load($excelPath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to load Excel file [{$excelPath}]: " . $e->getMessage(), 0, $e
            );
        }

        $allStudents = [];
        $sheets      = $spreadsheet->getAllSheets();
        $total       = count($sheets);

        foreach ($sheets as $index => $sheet) {
            $sheetName = $sheet->getTitle();
            Log::info('GeminiService: processing sheet', [
                'sheet' => $sheetName,
                'index' => $index + 1,
                'total' => $total,
            ]);

            // Convert this single sheet to text
            $lines         = ["=== SHEET: {$sheetName} ==="];
            $highestRow    = $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();

            for ($row = 1; $row <= $highestRow; $row++) {
                $rowData = [];
                foreach ($sheet->getRowIterator($row, $row) as $rowObj) {
                    $cellIterator = $rowObj->getCellIterator('A', $highestColumn);
                    $cellIterator->setIterateOnlyExistingCells(false);
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getFormattedValue();
                        if ($value !== '' && $value !== null) {
                            $rowData[] = trim((string) $value);
                        }
                    }
                }
                if (!empty($rowData)) {
                    $lines[] = implode(' | ', $rowData);
                }
            }

            $sheetText = mb_substr(implode("\n", $lines), 0, self::MAX_TEXT_CHARS);

            if (empty(trim($sheetText))) {
                Log::warning('GeminiService: empty sheet, skipping', ['sheet' => $sheetName]);
                continue;
            }

            $prompt = <<<PROMPT
You are an academic data extractor. Below is raw text from ONE student's transcript sheet (صحيفة طالب).

Extract:
1. The student code (كود الطالب) — a numeric string only.
2. ALL courses that appear in the transcript (both passed and failed/enrolled).
   For each course, determine if the student PASSED it or not.

   Passing grades: مقبول، جيد، جيد جداً، امتياز، ناجح، ≥ 60, D or above in letter grades.
   Not passed: راسب، غائب، محروم، W, F, or numeric grade < 60, or currently enrolled with no grade yet.

Return ONLY a valid JSON object (no markdown, no explanation):
{
  "student_code": "<numeric student code>",
  "courses": [
    {"name": "<course name in Arabic>", "is_passed": true},
    {"name": "<course name in Arabic>", "is_passed": false},
    ...
  ]
}

Rules:
- Include EVERY course listed in the transcript, not just passed ones.
- Use the EXACT course name as written in Arabic.
- If a course has no grade yet (currently enrolled), set is_passed to false.
- Do NOT include markdown code fences.
- Return a single JSON object, NOT an array.

--- SHEET TEXT START ---
{$sheetText}
--- SHEET TEXT END ---
PROMPT;

            // Wrap per-sheet API call in try-catch:
            // a transient error (503, timeout) on one sheet should NOT abort the rest.
            try {
                $raw = $this->callApi($this->buildTextPayload($prompt));
            } catch (\Throwable $e) {
                Log::error('GeminiService: sheet API call failed, skipping sheet', [
                    'sheet' => $sheetName,
                    'error' => $e->getMessage(),
                ]);
                continue; // skip this sheet, carry on with the next
            }

            // The response is a single JSON object, not an array
            $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
            $clean = preg_replace('/\s*```$/', '', $clean);
            $clean = trim($clean);

            $data = json_decode($clean, true);

            if (!is_array($data) || empty($data['student_code'])) {
                Log::warning('GeminiService: could not parse student from sheet', [
                    'sheet' => $sheetName,
                    'raw'   => substr($raw, 0, 300),
                ]);
                continue;
            }

            $studentData = [
                'student_code' => trim((string) $data['student_code']),
                'courses'      => array_values(array_filter(
                    array_map(function ($item) {
                        if (!is_array($item) || empty($item['name'])) {
                            return null;
                        }
                        return [
                            'name'      => trim((string) $item['name']),
                            'is_passed' => (bool) ($item['is_passed'] ?? false),
                        ];
                    }, (array) ($data['courses'] ?? []))
                )),
            ];

            $allStudents[] = $studentData;

            // ── Fire progressive-save callback immediately ─────────────────
            if ($onStudent !== null) {
                try {
                    $onStudent($studentData);
                } catch (\Throwable $e) {
                    // Log but don't abort — saving one student failing shouldn't stop the rest
                    Log::error('GeminiService: onStudent callback failed', [
                        'student' => $studentData['student_code'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('GeminiService: all sheets processed', ['student_count' => count($allStudents)]);

        return $allStudents;
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Local text extractors
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Extract all text from a PDF file using smalot/pdfparser.
     *
     * @throws RuntimeException if the PDF cannot be parsed.
     */
    private function extractTextFromPdf(string $pdfPath): string
    {
        // PDF parsing can be memory-intensive for large files;
        // temporarily raise the limit for this operation only.
        $prevMemory = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        try {
            $config = new \Smalot\PdfParser\Config();
            $config->setRetainImageContent(false); // skip image data to save memory

            $parser = new PdfParser([], $config);
            $pdf    = $parser->parseFile($pdfPath);
            $text   = $pdf->getText();

            if (empty(trim($text))) {
                throw new RuntimeException(
                    'PDF text extraction returned empty content. '
                    . 'The file may be a scanned image PDF (not text-based).'
                );
            }

            return $text;

        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to extract text from PDF [{$pdfPath}]: " . $e->getMessage(),
                0,
                $e
            );
        } finally {
            ini_set('memory_limit', $prevMemory); // restore original limit
        }
    }

    /**
     * Extract all text from an XLSX file using PhpSpreadsheet.
     * Each sheet is separated by a clear header, each row by newline.
     *
     * @throws RuntimeException if the Excel file cannot be read.
     */
    private function extractTextFromExcel(string $excelPath): string
    {
        try {
            $spreadsheet = SpreadsheetIOFactory::load($excelPath);
            $lines       = [];

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $sheetName = $sheet->getTitle();
                $lines[]   = "=== SHEET: {$sheetName} ===";

                $highestRow    = $sheet->getHighestDataRow();
                $highestColumn = $sheet->getHighestDataColumn();

                for ($row = 1; $row <= $highestRow; $row++) {
                    $rowData = [];

                    foreach ($sheet->getRowIterator($row, $row) as $rowObj) {
                        $cellIterator = $rowObj->getCellIterator('A', $highestColumn);
                        $cellIterator->setIterateOnlyExistingCells(false);

                        foreach ($cellIterator as $cell) {
                            $value = $cell->getFormattedValue();
                            if ($value !== '' && $value !== null) {
                                $rowData[] = trim((string) $value);
                            }
                        }
                    }

                    if (!empty($rowData)) {
                        $lines[] = implode(' | ', $rowData);
                    }
                }

                $lines[] = ''; // blank line between sheets
            }

            $text = implode("\n", $lines);

            if (empty(trim($text))) {
                throw new RuntimeException('Excel text extraction returned empty content.');
            }

            return $text;

        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to extract text from Excel [{$excelPath}]: " . $e->getMessage(),
                0,
                $e
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  Private helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build a text-only Gemini generateContent payload.
     */
    private function buildTextPayload(string $textPrompt): array
    {
        return [
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [
                        ['text' => $textPrompt],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => self::MAX_TOKENS,
                'temperature'     => 0.1,
                'topP'            => 0.9,
            ],
        ];
    }

    /**
     * POST to the Gemini API and return the raw text response.
     *
     * @throws RuntimeException on non-2xx response or empty content.
     */
    private function callApi(array $payload): string
    {
        $url = sprintf(
            '%s/%s:generateContent?key=%s',
            self::API_BASE,
            self::MODEL,
            $this->apiKey
        );

        $http = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout(300); // gemini-2.5-flash (thinking model) can take 2-3 min for large inputs

        if (app()->environment('local', 'development')) {
            $http = $http->withoutVerifying();
        }

        $response = $http->post($url, $payload);

        if ($response->failed()) {
            $status    = $response->status();
            $errorBody = $response->json('error.message', $response->body());

            if ($status === 429) {
                preg_match('/retry in ([\d.]+)s/i', $errorBody, $m);
                $retryAfter = isset($m[1]) ? (int) ceil((float) $m[1]) + 2 : 60;

                Log::warning('GeminiService: rate-limited (429), sleeping before retry', [
                    'retry_after_seconds' => $retryAfter,
                ]);

                sleep($retryAfter);
            }

            // 503 = server overloaded — sleep 30s then retry once
            if ($status === 503) {
                Log::warning('GeminiService: server overloaded (503), sleeping 30s then retrying once');
                sleep(30);

                $response = $http->post($url, $payload);

                if (!$response->failed()) {
                    // Retry succeeded — fall through to text extraction below
                    $text = $response->json('candidates.0.content.parts.0.text');
                    if (!empty($text)) {
                        return $text;
                    }
                }

                // Retry also failed — throw the original error
            }

            Log::error('GeminiService: API call failed', [
                'status' => $status,
                'error'  => $errorBody,
            ]);

            throw new RuntimeException("Gemini API error [{$status}]: {$errorBody}");
        }

        $text = $response->json('candidates.0.content.parts.0.text');

        if (empty($text)) {
            $finishReason = $response->json('candidates.0.finishReason');
            Log::warning('GeminiService: Empty response', ['finishReason' => $finishReason]);
            throw new RuntimeException(
                "Gemini returned empty content. Finish reason: {$finishReason}"
            );
        }

        return $text;
    }

    /**
     * Strip potential markdown fences and decode JSON array from model output.
     *
     * @throws RuntimeException if the output cannot be decoded as a JSON array.
     */
    private function parseJsonArray(string $raw, string $context): array
    {
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = preg_replace('/\s*```$/', '', $clean);
        $clean = trim($clean);

        if (str_starts_with($clean, '{')) {
            $decoded = json_decode($clean, true);
            if (is_array($decoded)) {
                foreach ($decoded as $v) {
                    if (is_array($v)) {
                        $clean = json_encode($v);
                        break;
                    }
                }
            }
        }

        $data = json_decode($clean, true);

        if (is_array($data)) {
            return $data;
        }

        // ── Partial-JSON recovery ─────────────────────────────────────────────
        // The model sometimes truncates a long JSON array mid-way.
        // Try to salvage every complete {...} object before the cut-off point.
        Log::warning('GeminiService: JSON decode failed, attempting partial recovery', [
            'context' => $context,
            'raw_len' => strlen($raw),
        ]);

        $recovered = $this->recoverPartialJsonArray($clean);

        if (!empty($recovered)) {
            Log::info('GeminiService: partial JSON recovery succeeded', [
                'context'         => $context,
                'recovered_count' => count($recovered),
            ]);
            return $recovered;
        }

        // Full failure — log and throw
        Log::error('GeminiService: Failed to parse JSON', [
            'context' => $context,
            'raw'     => substr($raw, 0, 500),
        ]);
        throw new RuntimeException(
            "GeminiService: Could not parse JSON for [{$context}]. "
            . 'Raw response starts with: ' . substr($raw, 0, 200)
        );
    }

    /**
     * Extract all complete JSON objects from a potentially truncated JSON array string.
     *
     * Strategy: scan character-by-character, track brace depth, and collect
     * every top-level {...} block that is fully closed.
     */
    private function recoverPartialJsonArray(string $text): array
    {
        $results = [];
        $len     = strlen($text);
        $i       = 0;

        // Skip leading whitespace / array bracket
        while ($i < $len && in_array($text[$i], [' ', "\n", "\r", "\t", '['])) {
            $i++;
        }

        while ($i < $len) {
            if ($text[$i] !== '{') {
                $i++;
                continue;
            }

            // Found the start of an object — read until its matching closing brace
            $depth  = 0;
            $start  = $i;
            $inStr  = false;
            $escape = false;

            for ($j = $i; $j < $len; $j++) {
                $ch = $text[$j];

                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($ch === '\\' && $inStr) {
                    $escape = true;
                    continue;
                }

                if ($ch === '"') {
                    $inStr = !$inStr;
                    continue;
                }

                if ($inStr) {
                    continue;
                }

                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        // Complete object found
                        $objStr = substr($text, $start, $j - $start + 1);
                        $obj    = json_decode($objStr, true);
                        if (is_array($obj)) {
                            $results[] = $obj;
                        }
                        $i = $j + 1;
                        break;
                    }
                }
            }

            if ($depth !== 0) {
                // Truncated object — stop here
                break;
            }
        }

        return $results;
    }

    /**
     * Validate and normalise course records returned by Gemini.
     */
    private function normalizeCourses(array $raw): array
    {
        $results = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code  = trim((string) ($item['code']  ?? ''));
            $name  = trim((string) ($item['name']  ?? ''));
            $hours = (int) ($item['credit_hours']  ?? 0);

            if (empty($code) || empty($name)) {
                continue;
            }

            $results[] = [
                'code'         => $code,
                'name'         => $this->fixArabicVisualOrder($name),
                'credit_hours' => max(1, $hours),
                'prerequisite' => !empty($item['prerequisite']) ? trim((string) $item['prerequisite']) : null,
            ];
        }

        return $results;
    }

    /**
     * Validate and normalise student records returned by Gemini.
     */
    private function normalizeStudents(array $raw): array
    {
        $results = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $code  = trim((string) ($item['student_code'] ?? ''));
            $codes = $item['passed_course_codes'] ?? [];

            if (empty($code)) {
                continue;
            }

            $results[] = [
                'student_code'        => $code,
                'passed_course_codes' => array_values(array_filter(
                    array_map(fn($c) => trim((string) $c), (array) $codes)
                )),
            ];
        }

        return $results;
    }

    /**
     * Detect and fix Arabic text stored in "visual order" (common in PDFs).
     *
     * In visual-order PDFs, Arabic characters within each word are stored
     * in reverse (e.g. "ةيزيلجنإ" instead of "إنجليزية"). We detect this by
     * checking if any Arabic word STARTS with ة (ta marbuta) — a letter that
     * only appears at the END of words in correct logical order.
     *
     * If reversed Arabic is detected, we reverse the characters inside every
     * Arabic word in the string (non-Arabic tokens are left untouched).
     */
    private function fixArabicVisualOrder(string $text): string
    {
        // Quick check: does the string even contain Arabic?
        if (!preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
            return $text;
        }

        // Heuristic: if any Arabic word starts with ة or ى or ئ or ؤ
        // (letters that ONLY appear at word ends in logical Arabic),
        // the text is likely in visual/reversed order.
        $isReversed = (bool) preg_match(
            '/(?<![^\s])[\x{0629}\x{0649}\x{0626}\x{0624}]/u',
            $text
        );

        // Simpler, more reliable check: first Arabic character is ة
        if (!$isReversed) {
            $isReversed = (bool) preg_match('/^[\x{0629}]/u', trim($text));
        }

        // Also detect if Arabic words start with common final-form letters
        if (!$isReversed) {
            $isReversed = (bool) preg_match('/\s[\x{0629}\x{0649}]/u', $text);
        }

        if (!$isReversed) {
            return $text;
        }

        // Reverse characters within each contiguous Arabic character sequence
        return preg_replace_callback(
            '/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]+/u',
            function (array $m): string {
                $chars = preg_split('//u', $m[0], -1, PREG_SPLIT_NO_EMPTY);
                return implode('', array_reverse($chars));
            },
            $text
        ) ?? $text;
    }
}

