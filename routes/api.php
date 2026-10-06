<?php

use App\Http\Controllers\Api\AcademicPeriodController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EducationInstitutionController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\PlanningPreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\StudyAvailabilityWindowController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\TeacherController;
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

    Route::get('/subjects', [SubjectController::class, 'index'])->name('subjects.index');
    Route::post('/subjects', [SubjectController::class, 'store'])->name('subjects.store');
    Route::get('/subjects/{subject}', [SubjectController::class, 'show'])->name('subjects.show');
    Route::patch('/subjects/{subject}', [SubjectController::class, 'update'])->name('subjects.update');
    Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy'])->name('subjects.destroy');

    Route::get('/teachers', [TeacherController::class, 'index'])->name('teachers.index');
    Route::post('/teachers', [TeacherController::class, 'store'])->name('teachers.store');
    Route::get('/teachers/{teacher}', [TeacherController::class, 'show'])->name('teachers.show');
    Route::patch('/teachers/{teacher}', [TeacherController::class, 'update'])->name('teachers.update');
    Route::delete('/teachers/{teacher}', [TeacherController::class, 'destroy'])->name('teachers.destroy');

    Route::post('/lessons', [LessonController::class, 'store'])->name('lessons.store');
    Route::get('/lessons/{lesson}', [LessonController::class, 'show'])->name('lessons.show');
    Route::patch('/lessons/{lesson}', [LessonController::class, 'update'])->name('lessons.update');
    Route::delete('/lessons/{lesson}', [LessonController::class, 'destroy'])->name('lessons.destroy');
    Route::post('/lessons/{lesson}/cancel', [LessonController::class, 'cancel'])->name('lessons.cancel');
    Route::post('/lessons/{lesson}/replacement', [LessonController::class, 'replacement'])->name('lessons.replacement.store');

    Route::get('/schedule/today', [ScheduleController::class, 'today'])->name('schedule.today');
    Route::get('/schedule/date/{date}', [ScheduleController::class, 'date'])->where('date', '.*')->name('schedule.date');
    Route::get('/schedule/week/{date}', [ScheduleController::class, 'week'])->where('date', '.*')->name('schedule.week');
});
