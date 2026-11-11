<?php

use App\Models\AiPendingAction;
use App\Models\StudySession;
use App\Models\User;
use App\Services\Imports\ScheduleWorkbookParser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// CLI fixtures are confined to the runner-owned, freshly initialized server.
if (PHP_SAPI !== 'cli' || getenv('PLANNER_SYSTEM_TEST') !== '1') {
    exit(2);
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$proof = json_decode(file_get_contents(getenv('PLANNER_SYSTEM_RUN').'/isolation.json'), true, 64, JSON_THROW_ON_ERROR);
$identity = DB::selectOne('SELECT @@datadir AS datadir, @@server_uuid AS uuid, DATABASE() AS db');
if (! $app->environment('local') || $identity->datadir !== $proof['datadir'] || $identity->uuid !== $proof['uuid'] || $identity->db !== $proof['database']) {
    exit(2);
}
$operation = $argv[1] ?? '';
if ($operation === 'workbook') {
    $book = IOFactory::load(__DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx');
    $template = app(ScheduleWorkbookParser::class)->parse(__DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx');
    $subjects = array_values(array_unique(array_column($template['rules'], 'subject_name')));
    $teachers = array_values(array_filter(array_unique(array_column($template['rules'], 'teacher_name'))));
    $mapping = [$template['group'] => 'Synthetic group'];
    foreach ($subjects as $i => $name) {
        $mapping[$name] = 'Synthetic Subject '.($i + 1);
    }
    foreach ($teachers as $i => $name) {
        $mapping[$name] = 'Syntheticteacher '.str_repeat(chr(65 + $i), 3);
    }
    foreach ($book->getWorksheetIterator() as $sheet) {
        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            $cell = $sheet->getCell($coordinate);
            if (is_string($cell->getValue()) && isset($mapping[$cell->getValue()])) {
                $cell->setValue($mapping[$cell->getValue()]);
            }
        }
    }
    $path = getenv('PLANNER_SYSTEM_RUN').'/synthetic.xlsx';
    (new Xlsx($book))->save($path);
    $parsed = app(ScheduleWorkbookParser::class)->parse($path);
    echo json_encode(['path' => $path, 'subjects' => array_values(array_unique(array_column($parsed['rules'], 'subject_name'))), 'teachers' => array_values(array_filter(array_unique(array_column($parsed['rules'], 'teacher_name'))))], JSON_THROW_ON_ERROR);
} elseif ($operation === 'history') {
    $owner = User::findOrFail((int) $argv[2]);
    $task = $owner->tasks()->findOrFail((int) $argv[3]);
    $session = StudySession::factory()->for($owner)->for($task)->create([
        'subtask_id' => null, 'status' => 'planned', 'starts_at' => now('UTC')->subHours(3), 'ends_at' => now('UTC')->subHours(2), 'actual_minutes' => null, 'completed_at' => null,
    ]);
    echo json_encode(['id' => $session->id]);
} elseif ($operation === 'snapshot') {
    $owner = User::findOrFail((int) $argv[2]);
    echo json_encode(['tasks' => $owner->tasks()->count(), 'sessions' => $owner->studySessions()->count(), 'notifications' => $owner->notifications()->count(), 'messages' => DB::table('ai_messages')->whereIn('conversation_id', $owner->aiConversations()->select('id'))->count(), 'database' => $identity->db], JSON_THROW_ON_ERROR);
} elseif ($operation === 'expire-action') {
    $owner = User::findOrFail((int) $argv[2]);
    $action = AiPendingAction::whereIn('conversation_id', $owner->aiConversations()->select('id'))->findOrFail($argv[3]);
    $action->forceFill(['expires_at' => now('UTC')->subMinute()])->save();
} elseif ($operation === 'expire-recovery') {
    $owner = User::findOrFail((int) $argv[2]);
    $review = $owner->recoverySuggestions()->findOrFail($argv[3]);
    $review->forceFill(['expires_at' => now('UTC')->subMinute()])->save();
} elseif ($operation === 'recovery-lock') {
    foreach (app(Schedule::class)->events() as $event) {
        if (str_contains($event->command ?? '', 'learning:detect-recovery')) {
            $mutex = $event->mutex;
            $first = $mutex->create($event);
            $second = $mutex->create($event);
            $mutex->forget($event);
            echo json_encode(['without_overlapping' => $event->withoutOverlapping, 'first_lock' => $first, 'second_lock' => $second]);
        }
    }
} else {
    exit(2);
}
