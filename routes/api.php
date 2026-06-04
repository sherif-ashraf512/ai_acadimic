<?php

use App\Http\Controllers\AdminMaterialRequestController;
use App\Http\Controllers\AdminOverviewController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\MaterialRequestController;
use App\Http\Controllers\SetupFilesController;
use App\Http\Controllers\StudentCoursesController;
use App\Http\Controllers\StudentRecommendController;
use App\Http\Controllers\StudentsController;
use Illuminate\Support\Facades\Route;

// ══════════════════════════════════════════════════════════════
//  Auth Routes (public)
// ══════════════════════════════════════════════════════════════
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

// ══════════════════════════════════════════════════════════════
//  Protected Routes
// ══════════════════════════════════════════════════════════════
Route::middleware(['auth:sanctum'])->group(function () {

    // ── Admin Routes ──────────────────────────────────────────
    Route::middleware(['admin'])->prefix('admin')->group(function () {

        // Overview / Dashboard
        Route::get('overview', [AdminOverviewController::class, 'index']);

        // Setup
        Route::post('setup/import', [SetupFilesController::class, 'import']);
        Route::post('setup/process', [SetupFilesController::class, 'process']);
        Route::get('setup/status', [SetupFilesController::class, 'status']);
        Route::get('setup/files', [SetupFilesController::class, 'index']);

        // Students management
        Route::get('students', [StudentsController::class, 'index']);
        Route::get('students/{id}', [StudentsController::class, 'show']);

        // Material Requests management
        Route::get('material-requests', [AdminMaterialRequestController::class, 'index']);
        Route::get('regular-requests', [AdminMaterialRequestController::class, 'regularRequests']);
        Route::get('graduation-requests', [AdminMaterialRequestController::class, 'graduationRequests']);
        Route::post('material-requests/approve', [AdminMaterialRequestController::class, 'approve']);
        Route::post('material-requests/reject', [AdminMaterialRequestController::class, 'reject']);

        // Advisor API status (admin can check if Python service is ready)
        Route::get('advisor/status', [StudentRecommendController::class, 'status']);
    });

    // ── Student Routes ────────────────────────────────────────
    Route::prefix('student')->group(function () {

        // Material Requests
        Route::get('material-requests', [MaterialRequestController::class, 'myRequests']);
        Route::post('material-requests/regular', [MaterialRequestController::class, 'storeRegular']);
        Route::post('material-requests/graduation', [MaterialRequestController::class, 'storeGraduation']);

        // Courses
        Route::get('courses', [StudentCoursesController::class, 'myCourses']);
        Route::get('courses/completed', [StudentCoursesController::class, 'completed']);
        Route::get('courses/remaining', [StudentCoursesController::class, 'remaining']);
        Route::get('courses/enrolled', [StudentCoursesController::class, 'enrolled']);

        // AI Course Recommendations
        Route::post('recommend', [StudentRecommendController::class, 'recommend']);
        Route::get('recommend/status', [StudentRecommendController::class, 'status']);
    });
});
