<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\StudySession;
use App\Models\Subtask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class StudySessionPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    public function test_exact_schema_defaults_and_factory_ownership(): void
    {
        $this->assertTableDefinition('study_sessions', [
            'id' => ['bigint unsigned', false, null],
            'user_id' => ['bigint unsigned', false, null],
            'task_id' => ['bigint unsigned', false, null],
            'subtask_id' => ['bigint unsigned', true, null],
            'rescheduled_from_session_id' => ['bigint unsigned', true, null],
            'starts_at' => ['datetime', false, null],
            'ends_at' => ['datetime', false, null],
            'status' => ['varchar(20)', false, 'planned'],
            'completed_at' => ['datetime', true, null],
            'actual_minutes' => ['smallint unsigned', true, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
        ]);
        $session = StudySession::factory()->create()->refresh();
        $this->assertSame($session->task->user_id, $session->user_id);
        $this->assertSame(StudySessionStatus::Planned, $session->status);
        foreach (['subtask_id', 'rescheduled_from_session_id', 'completed_at', 'actual_minutes'] as $column) {
            $this->assertNull($session->$column);
        }
        $this->assertNull($session->subtask);
        $this->assertNull($session->rescheduledFromSession);
        $this->assertNull($session->rescheduledToSession);
        $minimal = StudySession::create($session->only(['user_id', 'task_id', 'starts_at', 'ends_at']))->refresh();
        $this->assertSame(StudySessionStatus::Planned, $minimal->status);
        $task = Task::factory()->create();
        $explicit = StudySession::factory()->for($task)->create();
        $this->assertSame($task->user_id, $explicit->user_id);
        $this->assertSame(['planned', 'completed', 'missed', 'rescheduled', 'cancelled'], array_column(StudySessionStatus::cases(), 'value'));
    }

    #[DataProvider('statuses')]
    public function test_approved_statuses_cast_and_retain_completion_metadata(string $status): void
    {
        $values = ['status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-05 10:30:00' : null];
        $session = StudySession::factory()->create($values)->refresh();
        $this->assertSame(StudySessionStatus::from($status), $session->status);
        DB::table('study_sessions')->where('id', $session->id)->update($values);
        $this->assertSame(StudySessionStatus::from($status), $session->refresh()->status);
        foreach (['starts_at', 'ends_at'] as $column) {
            $this->assertInstanceOf(Carbon::class, $session->$column);
            $this->assertSame('UTC', $session->$column->timezoneName);
        }
        $this->assertTrue($session->starts_at->lt($session->ends_at));
        if ($status === 'completed') {
            $this->assertInstanceOf(Carbon::class, $session->completed_at);
            $this->assertNull($session->actual_minutes);
            $session->update(['actual_minutes' => 65535]);
            $this->assertSame(65535, $session->refresh()->actual_minutes);
            $session->update(['actual_minutes' => 1]);
            $this->assertSame(1, $session->refresh()->actual_minutes);
        } else {
            $this->assertNull($session->completed_at);
        }
    }

    public static function statuses(): array
    {
        return [['planned'], ['completed'], ['missed'], ['rescheduled'], ['cancelled']];
    }

    #[DataProvider('invalidValues')]
    public function test_mysql_rejects_invalid_values_on_insert_and_update(array $changes, int $error, ?string $constraint): void
    {
        $this->assertInsertAndUpdateRejected(StudySession::factory()->create(), $changes, $error, $constraint);
    }

    public static function invalidValues(): array
    {
        $cases = [
            'equal interval' => [['ends_at' => '2026-10-05 09:00:00'], 3819, 'study_sessions_valid_interval'],
            'reversed interval' => [['ends_at' => '2026-10-05 08:59:59'], 3819, 'study_sessions_valid_interval'],
            'unknown status' => [['status' => 'unsupported'], 3819, 'study_sessions_valid_status'],
            'empty status' => [['status' => ''], 3819, 'study_sessions_valid_status'],
            'completed without instant' => [['status' => 'completed'], 3819, 'study_sessions_completion_consistency'],
            'zero actual' => [['status' => 'completed', 'completed_at' => '2026-10-05 10:30:00', 'actual_minutes' => 0], 3819, 'study_sessions_positive_actual_minutes'],
            'negative actual' => [['actual_minutes' => -1], 1264, null],
        ];
        foreach (['planned', 'missed', 'rescheduled', 'cancelled'] as $status) {
            $cases[$status.' with timestamp'] = [['status' => $status, 'completed_at' => '2026-10-05 10:30:00'], 3819, 'study_sessions_completion_consistency'];
            $cases[$status.' with actual'] = [['status' => $status, 'actual_minutes' => 1], 3819, 'study_sessions_actual_requires_completion'];
        }

        return $cases;
    }

    public function test_relationships_isolation_and_subtask_set_null(): void
    {
        $subtask = Subtask::factory()->create();
        $task = $subtask->task;
        $session = StudySession::factory()->for($task)->for($subtask)->create();
        $other = StudySession::factory()->create();
        $this->assertTrue($session->user->is($task->user));
        $this->assertTrue($session->task->is($task));
        $this->assertTrue($session->subtask->is($subtask));
        foreach ([$task, $subtask, $task->user] as $parent) {
            $this->assertSame([$session->id], $parent->studySessions->modelKeys());
        }
        $this->assertSame([$other->id], $other->user->studySessions->modelKeys());
        $subtask->delete();
        $this->assertSame($subtask->id, $session->refresh()->subtask_id);
        $subtask->forceDelete();
        $this->assertNull($session->refresh()->subtask_id);
        $this->assertNull($session->subtask);
        $this->assertModelExists($session);
    }

    public function test_rescheduling_relationships_unique_predecessor_and_set_null(): void
    {
        $original = StudySession::factory()->create();
        $successor = StudySession::factory()->for($original->task)->create(['rescheduled_from_session_id' => $original->id]);
        $this->assertTrue($original->rescheduledToSession->is($successor));
        $this->assertTrue($successor->rescheduledFromSession->is($original));
        // Persisting a link does not perform the future Service lifecycle transition.
        $this->assertSame(StudySessionStatus::Planned, $original->refresh()->status);
        $unlinked = StudySession::factory()->for($original->task)->create();
        $this->assertInsertAndUpdateRejected($unlinked, ['rescheduled_from_session_id' => $original->id], 1062, 'study_sessions_rescheduled_from_session_id_unique');
        $original->delete();
        $this->assertDatabaseMissing('study_sessions', ['id' => $original->id]);
        $this->assertNull($successor->refresh()->rescheduled_from_session_id);
        $this->assertNull($successor->rescheduledFromSession);
    }

    public function test_database_permits_self_reference_but_future_service_must_reject_it(): void
    {
        $session = StudySession::factory()->create();
        // Invalid domain state: MySQL cannot enforce the self CHECK (3818/3823).
        DB::table('study_sessions')->where('id', $session->id)->update(['rescheduled_from_session_id' => $session->id]);
        $this->assertTrue($session->refresh()->rescheduledFromSession->is($session));
        $this->assertTrue($session->rescheduledToSession->is($session));
    }

    #[DataProvider('missingTargets')]
    public function test_foreign_keys_reject_missing_targets(string $column, string $model): void
    {
        $missing = $model::factory()->create();
        in_array($model, [Task::class, Subtask::class], true) ? $missing->forceDelete() : $missing->delete();
        $this->assertInsertAndUpdateRejected(StudySession::factory()->create(), [$column => $missing->id], 1452, 'study_sessions_'.$column.'_foreign');
    }

    public static function missingTargets(): array
    {
        return [['user_id', User::class], ['task_id', Task::class], ['subtask_id', Subtask::class], ['rescheduled_from_session_id', StudySession::class]];
    }

    public function test_task_soft_delete_preserves_history_and_hard_delete_cascades(): void
    {
        $task = Task::factory()->create();
        $sessions = [];
        foreach (self::statuses() as [$status]) {
            $sessions[] = StudySession::factory()->for($task)->create(['status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-05 10:30:00' : null]);
        }
        $task->delete();
        foreach ($sessions as $session) {
            $this->assertModelExists($session);
            // Cancellation of future planned rows requires the future Task Service.
            $this->assertSame($session->status, $session->refresh()->status);
        }
        $task->forceDelete();
        foreach ($sessions as $session) {
            $this->assertModelMissing($session);
        }
    }

    public function test_user_delete_cascades_sessions(): void
    {
        $session = StudySession::factory()->create();
        $other = StudySession::factory()->create();
        $session->user->delete();
        $this->assertModelMissing($session);
        $this->assertModelExists($other);
    }

    public function test_task_subtask_owner_and_predecessor_compatibility_are_service_invariants(): void
    {
        $session = StudySession::factory()->create();
        $otherSubtask = Subtask::factory()->create();
        $otherOriginal = StudySession::factory()->create();
        // Invalid domain references: the simple FKs enforce existence only.
        $session->update(['subtask_id' => $otherSubtask->id, 'rescheduled_from_session_id' => $otherOriginal->id]);
        $this->assertNotSame($session->task_id, $otherSubtask->task_id);
        $this->assertNotSame($session->user_id, $otherSubtask->task->user_id);
        $this->assertNotSame($session->user_id, $otherOriginal->user_id);
        $session->update(['task_id' => $otherSubtask->task_id]);
        $this->assertNotSame($session->user_id, $session->refresh()->task->user_id);
    }

    public function test_keys_indexes_and_only_supported_checks(): void
    {
        $this->assertForeignKeys('study_sessions', [
            'user_id' => ['users', 'cascade'], 'task_id' => ['tasks', 'cascade'],
            'subtask_id' => ['subtasks', 'set null'], 'rescheduled_from_session_id' => ['study_sessions', 'set null'],
        ]);
        $this->assertIndex('study_sessions', 'study_sessions_user_id_status_starts_at_index', ['user_id', 'status', 'starts_at']);
        $this->assertIndex('study_sessions', 'study_sessions_task_id_starts_at_index', ['task_id', 'starts_at']);
        $this->assertIndex('study_sessions', 'study_sessions_subtask_id_starts_at_index', ['subtask_id', 'starts_at']);
        $this->assertIndex('study_sessions', 'study_sessions_rescheduled_from_session_id_unique', ['rescheduled_from_session_id'], true);
        $this->assertEnforcedChecks('study_sessions', [
            'study_sessions_valid_interval', 'study_sessions_valid_status', 'study_sessions_positive_actual_minutes',
            'study_sessions_completion_consistency', 'study_sessions_actual_requires_completion',
        ]);
    }
}
