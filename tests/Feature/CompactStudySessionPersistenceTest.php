<?php

namespace Tests\Feature;

use App\Enums\StudySessionStatus;
use App\Models\StudySession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

#[Group('migration')]
#[Group('mysql')]
class CompactStudySessionPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    #[DataProvider('statuses')]
    public function test_approved_statuses_cast_and_retain_completion_metadata(string $status): void
    {
        $values = ['status' => $status, 'completed_at' => $status === 'completed' ? '2026-10-05 10:30:00' : null];
        $session = StudySession::factory()->create($values)->refresh();
        $this->assertSame(StudySessionStatus::from($status), $session->status);
        DB::table('study_sessions')->where('id', $session->id)->update($values);
        $this->assertSame(StudySessionStatus::from($status), $session->refresh()->status);
        foreach (['starts_at', 'ends_at'] as $column) {
            $this->assertInstanceOf(Carbon::class, $session->{$column});
            $this->assertSame('UTC', $session->{$column}->timezoneName);
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
        return [0 => ['planned']];
    }

    #[DataProvider('invalidValues')]
    public function test_mysql_rejects_invalid_values_on_insert_and_update(array $changes, int $error, ?string $constraint): void
    {
        $this->assertInsertAndUpdateRejected(StudySession::factory()->create(), $changes, $error, $constraint);
    }

    public static function invalidValues(): array
    {
        return ['equal interval' => [['ends_at' => '2026-10-05 09:00:00'], 3819, 'study_sessions_valid_interval']];
    }

    public function test_rescheduling_relationships_multiple_successors_and_set_null(): void
    {
        $original = StudySession::factory()->create();
        $successor = StudySession::factory()->for($original->task)->create(['rescheduled_from_session_id' => $original->id]);
        $this->assertTrue($original->rescheduledToSessions->first()->is($successor));
        $this->assertTrue($successor->rescheduledFromSession->is($original));
        $this->assertSame(StudySessionStatus::Planned, $original->refresh()->status);
        $unlinked = StudySession::factory()->for($original->task)->create();
        $unlinked->update(['rescheduled_from_session_id' => $original->id]);
        $this->assertEqualsCanonicalizing([$successor->id, $unlinked->id], $original->fresh()->rescheduledToSessions->modelKeys());
        $original->delete();
        $this->assertDatabaseMissing('study_sessions', ['id' => $original->id]);
        $this->assertNull($successor->refresh()->rescheduled_from_session_id);
        $this->assertNull($successor->rescheduledFromSession);
    }

    public function test_database_permits_self_reference_but_future_service_must_reject_it(): void
    {
        $session = StudySession::factory()->create();
        DB::table('study_sessions')->where('id', $session->id)->update(['rescheduled_from_session_id' => $session->id]);
        $this->assertTrue($session->refresh()->rescheduledFromSession->is($session));
        $this->assertTrue($session->rescheduledToSessions->first()->is($session));
    }
}
