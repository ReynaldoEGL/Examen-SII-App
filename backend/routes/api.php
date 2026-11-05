<?php

use App\Http\Controllers\Api\Internal\AdminAuthController;
use App\Http\Controllers\Api\Internal\AdminSubjectController;
use App\Http\Controllers\Api\Internal\StudentAuthController;
use App\Http\Controllers\Api\Internal\StudentDataController;
use App\Http\Controllers\Api\Internal\StudentPlannerController;
use Illuminate\Support\Facades\Route;

Route::prefix('internal')->middleware('service.signature')->group(function (): void {
    Route::prefix('student')->group(function (): void {
        Route::post('/login', [StudentAuthController::class, 'login'])->middleware('throttle:student-login');
        Route::middleware(['app.session', 'student.session'])->group(function (): void {
            Route::post('/logout', [StudentAuthController::class, 'logout']);
            Route::get('/profile', [StudentDataController::class, 'profile']);
            Route::get('/grades', [StudentDataController::class, 'grades']);
            Route::get('/kardex', [StudentDataController::class, 'kardex']);
            Route::get('/schedule', [StudentDataController::class, 'schedule']);
            Route::post('/planner/preview', [StudentPlannerController::class, 'preview'])->middleware('throttle:student-planner');
        });
    });

    Route::prefix('admin')->group(function (): void {
        Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');
        Route::middleware(['app.session', 'admin.session'])->group(function (): void {
            Route::post('/logout', [AdminAuthController::class, 'logout']);
            Route::get('/me', [AdminAuthController::class, 'me']);
            Route::middleware('admin.role:superadmin,editor')->group(function (): void {
                Route::get('/subjects', [AdminSubjectController::class, 'index']);
                Route::post('/subjects', [AdminSubjectController::class, 'store']);
                Route::put('/subjects/{subject}', [AdminSubjectController::class, 'update']);
                Route::delete('/subjects/{subject}', [AdminSubjectController::class, 'destroy']);
                Route::post('/subjects/import', [AdminSubjectController::class, 'importCsv']);
            });
        });
    });
});
