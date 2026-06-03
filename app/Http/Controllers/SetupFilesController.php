<?php

namespace App\Http\Controllers;

use App\Imports\StudentsImport;
use App\Jobs\ProcessSetupFilesJob;
use App\Models\Course;
use App\Models\SetupFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SetupFilesController extends Controller
{
    // ══════════════════════════════════════════════════════════════════════════
    //  GET /admin/setup/files
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Return the current setup file record (file URLs + processing status).
     */
    public function index(Request $request)
    {
        $setupFile = SetupFile::first();

        if (!$setupFile) {
            return $this->error('Setup file not found', 404);
        }

        return $this->success($setupFile, 'Setup file fetched successfully');
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  POST /admin/setup/import
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Upload the college catalog (PDF) and student transcript (XLSX).
     * Saves the files and queues StudentsImport to create/update student Users.
     *
     * NOTE: Call POST /admin/setup/process AFTER this job completes
     * to run the Gemini AI extraction — this guarantees all students
     * exist in the DB before Gemini tries to link their passed courses.
     */
    public function import(Request $request)
    {
        $request->validate([
            'collage_list'    => 'required|file|mimes:pdf',
            'student_formula' => 'required|file|mimes:xlsx,xls',
        ]);

        $pdfFile   = $request->file('collage_list');
        $excelFile = $request->file('student_formula');

        $newPdfPath   = "setup_files/{$pdfFile->getClientOriginalName()}";
        $newExcelPath = "setup_files/{$excelFile->getClientOriginalName()}";

        // ── Delete OLD files BEFORE saving new ones ─────────────────────────
        // If we delete AFTER saving, and the filename is the same,
        // we'd immediately delete the file we just saved!
        $setupFile = SetupFile::first();

        if ($setupFile) {
            $oldPdf   = $setupFile->getRawOriginal('collage_list');
            $oldExcel = $setupFile->getRawOriginal('student_formula');

            // Only delete if the new filename differs from the old one
            if ($oldPdf && $oldPdf !== $newPdfPath) {
                Storage::disk('public')->delete($oldPdf);
            }
            if ($oldExcel && $oldExcel !== $newExcelPath) {
                Storage::disk('public')->delete($oldExcel);
            }
        }

        // ── Save new files to disk ──────────────────────────────────────────
        $pdfFile->storeAs('setup_files', $pdfFile->getClientOriginalName(), 'public');
        $excelFile->storeAs('setup_files', $excelFile->getClientOriginalName(), 'public');

        // ── Create / update SetupFile record ───────────────────────────────
        if ($setupFile) {
            $setupFile->update([
                'collage_list'      => $newPdfPath,
                'student_formula'   => $newExcelPath,
                'processing_status' => 'pending',
                'processing_error'  => null,
                'processed_at'      => null,
            ]);
        } else {
            $setupFile = SetupFile::create([
                'collage_list'      => $newPdfPath,
                'student_formula'   => $newExcelPath,
                'processing_status' => 'pending',
            ]);
        }

        // ── Queue student import → triggers ProcessSetupFilesJob on finish ──
        (new StudentsImport($setupFile->id))->queue($request->file('student_formula'));

        return $this->success(
            $setupFile->fresh(),
            'Files uploaded. Students are being imported — call /status to check processing once done.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  POST /admin/setup/process
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Manually re-trigger AI processing for already-uploaded files.
     * Useful when the first job failed or the admin wants a fresh extraction.
     */
    public function process(Request $request)
    {
        $setupFile = SetupFile::first();

        if (!$setupFile) {
            return $this->error('No setup files uploaded yet.', 404);
        }

        if ($setupFile->processing_status === 'processing') {
            return $this->error('Processing is already in progress.', 409);
        }

        // ── Make sure physical files still exist on disk ────────────────────
        $pdfPath   = realpath(Storage::disk('public')->path($setupFile->getRawOriginal('collage_list')));
        $excelPath = realpath(Storage::disk('public')->path($setupFile->getRawOriginal('student_formula')));

        if (!$pdfPath || !file_exists($pdfPath) || !$excelPath || !file_exists($excelPath)) {
            return $this->error(
                'Uploaded files are missing from disk. Please re-upload via /import.',
                422
            );
        }

        // Reset status before re-dispatching
        $setupFile->update([
            'processing_status' => 'pending',
            'processing_error'  => null,
            'processed_at'      => null,
        ]);

        ProcessSetupFilesJob::dispatch($setupFile->id);

        return $this->success(
            $setupFile->fresh(),
            'AI re-processing has been queued.'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  GET /admin/setup/status
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Return lightweight processing status (polling endpoint).
     */
    public function status(Request $request)
    {
        $setupFile = SetupFile::select([
            'id', 'processing_status', 'processing_error', 'processed_at', 'updated_at',
        ])->first();

        if (!$setupFile) {
            return $this->error('No setup files found.', 404);
        }

        $extra = [];

        if ($setupFile->processing_status === 'completed') {
            $extra['courses_count'] = Course::count();
        }

        return $this->success(array_merge($setupFile->toArray(), $extra), 'Status fetched.');
    }
}
