<?php

use App\Exceptions\ReminderConflictException;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use App\Services\Planning\StudySessionLifecycleService;
use App\Services\Planning\StudySessionReschedulingService;
use App\Services\Reminders\ReminderService;
use App\Services\Tasks\SubtaskService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
    || $connection->getDatabaseName() !== 'student_planner_testing'
    || $connection->selectOne('SELECT DATABASE() AS name')->name !== 'student_planner_testing') {
    throw new RuntimeException('Unsafe concurrency test database.');
}
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now']));
$owner = User::whereKey($input['owner_id'])->firstOrFail();
fwrite(STDOUT, json_encode(['ready' => true, 'connection_id' => $connection->selectOne('SELECT CONNECTION_ID() AS id')->id])."\n");
fflush(STDOUT);
if (isset($input['now_after_lock'])) {
    DB::listen(function ($query) use ($input): void {
        if (str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse($input['now_after_lock']));
        }
    });
}
try {
    $service = $app->make(ReminderService::class);
    switch ($input['action']) {
        case 'create':
            $reminder = $service->create($owner, $input['data']);
            $outcome = ['status' => 'created', 'id' => $reminder->id];
            break;
        case 'update':
            $service->update($owner, (string) $input['reminder_id'], $input['data']);
            $outcome = ['status' => 'updated'];
            break;
        case 'cancel':
            $service->cancel($owner, (string) $input['reminder_id']);
            $outcome = ['status' => 'cancelled'];
            break;
        case 'task_delete':
            $app->make(TaskService::class)->delete($owner, Task::findOrFail($input['task_id']));
            $outcome = ['status' => 'deleted'];
            break;
        case 'child_delete':
            $app->make(SubtaskService::class)->delete($owner, Task::findOrFail($input['task_id']), Subtask::findOrFail($input['subtask_id']));
            $outcome = ['status' => 'deleted'];
            break;
        case 'session_cancel':
            $app->make(StudySessionLifecycleService::class)->cancel($owner, (string) $input['session_id']);
            $outcome = ['status' => 'cancelled'];
            break;
        case 'session_reschedule':
            $app->make(StudySessionReschedulingService::class)->reschedule($owner, (string) $input['session_id'], '2026-10-19T11:00:00Z', '2026-10-19T12:00:00Z');
            $outcome = ['status' => 'rescheduled'];
            break;
    }
} catch (ReminderConflictException) {
    $outcome = ['status' => 'conflict'];
} catch (ValidationException) {
    $outcome = ['status' => 'invalid'];
} catch (ModelNotFoundException) {
    $outcome = ['status' => 'not_found'];
} catch (Throwable $exception) {
    $outcome = ['status' => 'error', 'class' => $exception::class];
}
fwrite(STDOUT, json_encode($outcome)."\n");
