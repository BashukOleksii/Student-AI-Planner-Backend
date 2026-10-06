<?php

use App\Http\Controllers\Api\AcademicPeriodController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EducationInstitutionController;
use App\Http\Controllers\Api\PlanningPreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\StudyAvailabilityWindowController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/planning-preferences', [PlanningPreferenceController::class, 'show'])->name('planning-preferences.show');
    Route::put('/planning-preferences', [PlanningPreferenceController::class, 'update'])->name('planning-preferences.update');
    Route::get('/study-availability-windows', [StudyAvailabilityWindowController::class, 'index'])->name('study-availability-windows.index');
    Route::post('/study-availability-windows', [StudyAvailabilityWindowController::class, 'store'])->name('study-availability-windows.store');
    Route::patch('/study-availability-windows/{studyAvailabilityWindow}', [StudyAvailabilityWindowController::class, 'update'])->name('study-availability-windows.update');
    Route::delete('/study-availability-windows/{studyAvailabilityWindow}', [StudyAvailabilityWindowController::class, 'destroy'])->name('study-availability-windows.destroy');

    Route::get('/education-institutions', [EducationInstitutionController::class, 'index'])->name('education-institutions.index');
    Route::post('/education-institutions', [EducationInstitutionController::class, 'store'])->name('education-institutions.store');
    Route::get('/education-institutions/{educationInstitution}', [EducationInstitutionController::class, 'show'])->name('education-institutions.show');
    Route::patch('/education-institutions/{educationInstitution}', [EducationInstitutionController::class, 'update'])->name('education-institutions.update');
    Route::delete('/education-institutions/{educationInstitution}', [EducationInstitutionController::class, 'destroy'])->name('education-institutions.destroy');

    Route::get('/academic-periods', [AcademicPeriodController::class, 'index'])->name('academic-periods.index');
    Route::post('/academic-periods', [AcademicPeriodController::class, 'store'])->name('academic-periods.store');
    Route::get('/academic-periods/{academicPeriod}', [AcademicPeriodController::class, 'show'])->name('academic-periods.show');
    Route::patch('/academic-periods/{academicPeriod}', [AcademicPeriodController::class, 'update'])->name('academic-periods.update');
    Route::delete('/academic-periods/{academicPeriod}', [AcademicPeriodController::class, 'destroy'])->name('academic-periods.destroy');
});
