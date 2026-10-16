<?php

use App\Http\Controllers\Api\AcademicPeriodController;
use App\Http\Controllers\Api\AiConversationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EducationInstitutionController;
use App\Http\Controllers\Api\FreeSlotController;
use App\Http\Controllers\Api\LessonController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PlanningPreferenceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProgressComparisonController;
use App\Http\Controllers\Api\ProgressStatisticsController;
use App\Http\Controllers\Api\ProgressTrendsController;
use App\Http\Controllers\Api\ReminderController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\ScheduleImportController;
use App\Http\Controllers\Api\StudyAvailabilityWindowController;
use App\Http\Controllers\Api\StudyPlanController;
use App\Http\Controllers\Api\StudyReplanningController;
use App\Http\Controllers\Api\StudySessionController;
use App\Http\Controllers\Api\SubjectController;
use App\Http\Controllers\Api\SubtaskController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum')->name('logout');
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('ai/conversations')->name('ai.conversations.')->group(function (): void {
        Route::post('/', [AiConversationController::class, 'store'])->name('store');
        Route::get('/', [AiConversationController::class, 'index'])->name('index');
        Route::get('/{conversation}', [AiConversationController::class, 'show'])->whereNumber('conversation')->name('show');
        Route::get('/{conversation}/messages', [AiConversationController::class, 'messages'])->whereNumber('conversation')->name('messages');
        Route::post('/{conversation}/messages', [AiConversationController::class, 'send'])->whereNumber('conversation')->middleware('throttle:ai-messages')->name('send');
    });

    Route::get('/statistics/reports/comparison', [ProgressComparisonController::class, 'index'])->name('statistics.reports.comparison');
    Route::get('/statistics/progress', [ProgressStatisticsController::class, 'index'])->name('statistics.progress');
    Route::get('/statistics/trends', [ProgressTrendsController::class, 'index'])->name('statistics.trends');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');

    Route::get('/reminders', [ReminderController::class, 'index'])->name('reminders.index');
    Route::post('/reminders', [ReminderController::class, 'store'])->name('reminders.store');
    Route::get('/reminders/{reminder}', [ReminderController::class, 'show'])->whereNumber('reminder')->name('reminders.show');
    Route::patch('/reminders/{reminder}', [ReminderController::class, 'update'])->whereNumber('reminder')->name('reminders.update');
    Route::post('/reminders/{reminder}/cancel', [ReminderController::class, 'cancel'])->whereNumber('reminder')->name('reminders.cancel');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('/planning/free-slots', [FreeSlotController::class, 'index'])->name('planning.free-slots.index');
    Route::post('/planning/study-plans/preview', [StudyPlanController::class, 'preview'])->name('planning.study-plans.preview');
    Route::post('/planning/replanning/preview', [StudyReplanningController::class, 'preview'])->name('planning.replanning.preview');
    Route::post('/planning/replanning/confirm', [StudyReplanningController::class, 'confirm'])->name('planning.replanning.confirm');
    Route::post('/planning/study-plans/confirm', [StudyPlanController::class, 'confirm'])->name('planning.study-plans.confirm');
    Route::get('/study-sessions', [StudySessionController::class, 'index'])->name('study-sessions.index');
    Route::get('/study-sessions/{studySession}', [StudySessionController::class, 'show'])->whereNumber('studySession')->name('study-sessions.show');
    Route::post('/study-sessions/{studySession}/complete', [StudySessionController::class, 'complete'])->whereNumber('studySession')->name('study-sessions.complete');
    Route::post('/study-sessions/{studySession}/miss', [StudySessionController::class, 'miss'])->whereNumber('studySession')->name('study-sessions.miss');
    Route::post('/study-sessions/{studySession}/cancel', [StudySessionController::class, 'cancel'])->whereNumber('studySession')->name('study-sessions.cancel');
    Route::post('/study-sessions/{studySession}/reschedule', [StudySessionController::class, 'reschedule'])->whereNumber('studySession')->name('study-sessions.reschedule');

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

    Route::post('/schedule-imports', [ScheduleImportController::class, 'store'])->name('schedule-imports.store');
    Route::get('/schedule-imports/{scheduleImportBatch}', [ScheduleImportController::class, 'show'])->name('schedule-imports.show');
    Route::post('/schedule-imports/{scheduleImportBatch}/revalidate', [ScheduleImportController::class, 'revalidate'])->name('schedule-imports.revalidate');
    Route::post('/schedule-imports/{scheduleImportBatch}/commit', [ScheduleImportController::class, 'commit'])->name('schedule-imports.commit');

    Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::get('/tasks/{task}/progress', [TaskController::class, 'progress'])->name('tasks.progress');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
    Route::post('/tasks/{task}/reopen', [TaskController::class, 'reopen'])->name('tasks.reopen');

    Route::scopeBindings()->group(function (): void {
        Route::get('/tasks/{task}/subtasks', [SubtaskController::class, 'index'])->name('tasks.subtasks.index');
        Route::post('/tasks/{task}/subtasks', [SubtaskController::class, 'store'])->name('tasks.subtasks.store');
        Route::post('/tasks/{task}/subtasks/reorder', [SubtaskController::class, 'reorder'])->name('tasks.subtasks.reorder');
        Route::get('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'show'])->name('tasks.subtasks.show');
        Route::patch('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'update'])->name('tasks.subtasks.update');
        Route::delete('/tasks/{task}/subtasks/{subtask}', [SubtaskController::class, 'destroy'])->name('tasks.subtasks.destroy');
        Route::post('/tasks/{task}/subtasks/{subtask}/complete', [SubtaskController::class, 'complete'])->name('tasks.subtasks.complete');
        Route::post('/tasks/{task}/subtasks/{subtask}/reopen', [SubtaskController::class, 'reopen'])->name('tasks.subtasks.reopen');
    });
});
