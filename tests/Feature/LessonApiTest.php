<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Enums\LessonType;
use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Schedule\LessonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonApiTest extends TestCase
{
    use RefreshDatabase;

    private function values(Subject $subject, array $overrides = []): array
    {
        return array_replace([
            'subject_id' => $subject->id, 'type' => 'lecture',
            'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z',
        ], $overrides);
    }

    private function url(Lesson $lesson): string
    {
        return '/api/lessons/'.$lesson->id;
    }

    public function test_all_four_endpoints_require_authentication(): void
    {
        $lesson = Lesson::factory()->create();
        $this->postJson('/api/lessons', $this->values($lesson->subject))->assertUnauthorized();
        $this->getJson($this->url($lesson))->assertUnauthorized();
        $this->patchJson($this->url($lesson), [])->assertUnauthorized();
        $this->deleteJson($this->url($lesson))->assertUnauthorized();
    }

    public function test_minimal_create_sets_trusted_owner_active_status_and_exact_public_shape(): void
    {
        $subject = Subject::factory()->create();
        $other = User::factory()->create();
        $response = $this->actingAs($subject->user, 'web')->postJson('/api/lessons', [
            ...$this->values($subject), 'user_id' => $other->id, 'id' => 999999, 'status' => 'cancelled',
            'schedule_import_batch_id' => 999999, 'import_fingerprint' => str_repeat('a', 64), 'replaces_lesson_id' => 999999,
            'deleted_at' => '2000-01-01', 'created_at' => '2000-01-01', 'updated_at' => '2000-01-01',
        ])->assertCreated();
        $lesson = Lesson::sole();
        $response->assertExactJson(['data' => [
            'id' => $lesson->id, 'academic_period_id' => null, 'subject_id' => $subject->id, 'teacher_id' => null,
            'type' => 'lecture', 'room' => null, 'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z', 'status' => 'active',
        ]]);
        $this->assertSame($subject->user_id, $lesson->user_id);
        $this->assertNotSame(999999, $lesson->id);
        $this->assertSame(LessonStatus::Active, $lesson->status);
        foreach (['schedule_import_batch_id', 'import_fingerprint', 'replaces_lesson_id', 'deleted_at'] as $field) {
            $this->assertNull($lesson->$field);
        }
        $this->assertNotSame('2000-01-01', $lesson->getRawOriginal('created_at'));
        $this->assertDatabaseHas('lessons', ['id' => $lesson->id, 'starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 10:00:00']);
        $this->getJson($this->url($lesson))->assertOk()->assertExactJson($response->json());
    }

    public function test_optional_associations_may_have_different_institutions_but_same_user(): void
    {
        $user = User::factory()->create();
        $first = EducationInstitution::factory()->for($user)->create();
        $second = EducationInstitution::factory()->for($user)->create();
        $subject = Subject::factory()->for($user)->for($first)->create();
        $teacher = Teacher::factory()->for($user)->for($second)->create();
        $period = AcademicPeriod::factory()->for($user)->create();
        $this->actingAs($user, 'web')->postJson('/api/lessons', $this->values($subject, [
            'teacher_id' => $teacher->id, 'academic_period_id' => $period->id, 'room' => str_repeat('R', 100), 'type' => 'practical',
        ]))->assertCreated()->assertExactJson(['data' => [
            'id' => Lesson::sole()->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'academic_period_id' => $period->id,
            'type' => 'practical', 'room' => str_repeat('R', 100), 'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z', 'status' => 'active',
        ]]);
    }

    #[DataProvider('lessonTypes')]
    public function test_all_approved_types_are_accepted(string $type): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject, ['type' => $type]))
            ->assertCreated()->assertJsonPath('data.type', $type);
    }

    public static function lessonTypes(): array
    {
        return array_map(fn ($type) => [$type->value], LessonType::cases());
    }

    public function test_explicit_null_optionals_are_accepted_on_create(): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject, [
            'teacher_id' => null, 'academic_period_id' => null, 'room' => null,
        ]))->assertCreated()->assertJsonPath('data.teacher_id', null)->assertJsonPath('data.academic_period_id', null)->assertJsonPath('data.room', null);
    }

    public function test_post_requires_all_nonnullable_fields_and_no_general_index_exists(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/lessons', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['subject_id', 'type', 'starts_at', 'ends_at']);
        $this->getJson('/api/lessons')->assertStatus(405);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_manual_fields_fail_create_and_patch_without_changes(array $values, string $field): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        $before = $lesson->getAttributes();
        $this->actingAs($lesson->user, 'web')->postJson('/api/lessons', $this->values($lesson->subject, $values))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($lesson), $values)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->assertDatabaseCount('lessons', 1);
    }

    public static function invalidFields(): array
    {
        return [
            [['subject_id' => null], 'subject_id'], [['subject_id' => '1'], 'subject_id'], [['subject_id' => 0], 'subject_id'],
            [['teacher_id' => true], 'teacher_id'], [['teacher_id' => -1], 'teacher_id'], [['academic_period_id' => 1.5], 'academic_period_id'],
            [['academic_period_id' => '1'], 'academic_period_id'], [['type' => 'unsupported'], 'type'], [['type' => null], 'type'],
            [['room' => str_repeat('R', 101)], 'room'], [['room' => []], 'room'],
        ];
    }

    #[DataProvider('invalidTimestamps')]
    public function test_noncanonical_or_invalid_utc_is_rejected_on_create_and_patch(string $value): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        $before = $lesson->getAttributes();
        $this->actingAs($lesson->user, 'web');
        foreach (['starts_at', 'ends_at'] as $field) {
            $this->postJson('/api/lessons', $this->values($lesson->subject, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->patchJson($this->url($lesson), [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->assertDatabaseCount('lessons', 1);
    }

    public static function invalidTimestamps(): array
    {
        return [
            ['2026-10-06 09:00:00'], ['2026-10-06T09:00:00'], ['2026-10-06T12:00:00+03:00'], ['2026-10-06T09:00:00.123Z'],
            ['2026-10-06T09:00:00z'], ['2026-1-06T09:00:00Z'], ['2026-02-30T09:00:00Z'], ['2026-10-06T25:00:00Z'],
            ['2026-10-06T09:60:00Z'], ['2026-10-06T09:00:60Z'], ['0999-10-06T09:00:00Z'], ['tomorrow'], ["2026-10-06T09:00:00Z\0"],
            [' 2026-10-06T09:00:00Z'], ['2026-10-06T09:00:00Z '],
        ];
    }

    public function test_equal_reversed_and_partial_invalid_intervals_are_controlled_errors(): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        $before = $lesson->getAttributes();
        $this->actingAs($lesson->user, 'web');
        foreach (['2026-10-06T09:00:00Z', '2026-10-06T08:00:00Z'] as $end) {
            $this->postJson('/api/lessons', $this->values($lesson->subject, ['ends_at' => $end]))->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        }
        foreach ([['starts_at' => '2026-10-05T07:30:00Z'], ['starts_at' => '2026-10-05T08:00:00Z'], ['ends_at' => '2026-10-05T06:00:00Z']] as $values) {
            $this->patchJson($this->url($lesson), $values)->assertUnprocessable()->assertJsonValidationErrors('ends_at');
        }
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public function test_partial_updates_keep_omitted_values_clear_nullable_fields_and_ignore_internals(): void
    {
        $lesson = Lesson::factory()->create(['room' => '301'])->refresh();
        $teacher = Teacher::factory()->for($lesson->user)->create();
        $period = AcademicPeriod::factory()->for($lesson->user)->create();
        $subject = Subject::factory()->for($lesson->user)->create();
        $before = $lesson->getAttributes();
        $this->actingAs($lesson->user, 'web')->patchJson($this->url($lesson), [
            'room' => '302', 'status' => 'cancelled', 'user_id' => 999999, 'id' => 999999,
            'schedule_import_batch_id' => 999999, 'import_fingerprint' => str_repeat('a', 64), 'replaces_lesson_id' => 999999,
            'created_at' => '2000-01-01', 'updated_at' => '2000-01-01', 'deleted_at' => '2000-01-01',
        ])->assertOk()->assertExactJson(['data' => [
            'id' => $lesson->id, 'subject_id' => $lesson->subject_id, 'teacher_id' => null, 'academic_period_id' => null,
            'type' => 'lecture', 'room' => '302', 'starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:30:00Z', 'status' => 'active',
        ]]);
        $lesson->refresh();
        foreach (['id', 'user_id', 'created_at', 'starts_at', 'ends_at', 'schedule_import_batch_id', 'import_fingerprint', 'replaces_lesson_id', 'deleted_at', 'status'] as $field) {
            $this->assertSame($before[$field], $lesson->getRawOriginal($field));
        }
        $this->patchJson($this->url($lesson), ['subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'academic_period_id' => $period->id, 'type' => 'seminar'])->assertOk();
        $this->patchJson($this->url($lesson), ['teacher_id' => null, 'academic_period_id' => null, 'room' => null])->assertOk();
        $this->patchJson($this->url($lesson), [])->assertOk()->assertJsonPath('data.subject_id', $subject->id)->assertJsonPath('data.type', 'seminar');
        $this->assertNull($lesson->refresh()->teacher_id);
        $this->assertNull($lesson->academic_period_id);
        $this->assertNull($lesson->room);
    }

    public function test_service_reloads_latest_times_when_a_stale_model_is_passed(): void
    {
        $lesson = Lesson::factory()->create();
        $service = app(LessonService::class);
        $service->update($lesson->user, $lesson, ['ends_at' => '2026-10-05T09:00:00Z']);
        $updated = $service->update($lesson->user, $lesson, ['starts_at' => '2026-10-05T08:00:00Z', 'status' => 'cancelled', 'user_id' => 999999]);
        $this->assertSame('2026-10-05 08:00:00', $updated->getRawOriginal('starts_at'));
        $this->assertSame('2026-10-05 09:00:00', $updated->getRawOriginal('ends_at'));
        $this->assertSame(LessonStatus::Active, $updated->status);
        $this->assertSame($lesson->user_id, $updated->user_id);
    }

    public function test_utc_storage_and_responses_do_not_depend_on_server_or_profile_timezone(): void
    {
        $subject = Subject::factory()->create();
        $subject->user->update(['timezone' => 'Europe/Kyiv']);
        $originalTimezone = date_default_timezone_get();
        config(['app.timezone' => 'America/New_York']);
        date_default_timezone_set('America/New_York');
        try {
            // This UTC wall time lies in New York's nonexistent local DST hour.
            $response = $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject, [
                'starts_at' => '2026-03-08T02:30:00Z', 'ends_at' => '2026-03-08T03:30:00Z',
            ]))->assertCreated()->assertJsonPath('data.starts_at', '2026-03-08T02:30:00Z');
            $lesson = Lesson::sole();
            $this->assertSame('2026-03-08 02:30:00', $lesson->getRawOriginal('starts_at'));
            $this->getJson($this->url($lesson))->assertOk()->assertExactJson($response->json());
            $this->patchJson($this->url($lesson), ['ends_at' => '2026-03-08T04:30:00Z'])->assertOk()
                ->assertJsonPath('data.starts_at', '2026-03-08T02:30:00Z')->assertJsonPath('data.ends_at', '2026-03-08T04:30:00Z');
            $this->assertSame('2026-03-08 02:30:00', $lesson->refresh()->getRawOriginal('starts_at'));
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    #[DataProvider('overlappingIntervals')]
    public function test_all_overlap_shapes_fail_create(string $start, string $end): void
    {
        $subject = Subject::factory()->create();
        Lesson::factory()->for($subject->user)->for($subject)->create(['starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 10:00:00']);
        $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject, ['starts_at' => $start, 'ends_at' => $end]))
            ->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertDatabaseCount('lessons', 1);
    }

    public static function overlappingIntervals(): array
    {
        return [
            ['2026-10-06T09:30:00Z', '2026-10-06T10:30:00Z'], ['2026-10-06T08:30:00Z', '2026-10-06T09:30:00Z'],
            ['2026-10-06T08:00:00Z', '2026-10-06T11:00:00Z'], ['2026-10-06T09:15:00Z', '2026-10-06T09:45:00Z'],
            ['2026-10-06T09:00:00Z', '2026-10-06T10:00:00Z'],
        ];
    }

    public function test_both_adjacent_boundaries_are_allowed(): void
    {
        $subject = Subject::factory()->create();
        Lesson::factory()->for($subject->user)->for($subject)->create(['starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 10:00:00']);
        $this->actingAs($subject->user, 'web');
        foreach ([['2026-10-06T08:00:00Z', '2026-10-06T09:00:00Z'], ['2026-10-06T10:00:00Z', '2026-10-06T11:00:00Z']] as [$start, $end]) {
            $this->postJson('/api/lessons', $this->values($subject, ['starts_at' => $start, 'ends_at' => $end]))->assertCreated();
        }
        $this->assertDatabaseCount('lessons', 3);
    }

    #[DataProvider('ignoredConflictStates')]
    public function test_nonblocking_existing_lessons_do_not_conflict(LessonStatus $status, bool $trashed, bool $foreign): void
    {
        $subject = Subject::factory()->create();
        $owner = $foreign ? User::factory()->create() : $subject->user;
        $existing = Lesson::factory()->for($owner)->create(['status' => $status, 'starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 10:00:00']);
        if ($trashed) {
            $existing->delete();
        }
        $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject))->assertCreated();
    }

    public static function ignoredConflictStates(): array
    {
        return [[LessonStatus::Cancelled, false, false], [LessonStatus::Replaced, false, false], [LessonStatus::Active, true, false], [LessonStatus::Active, false, true]];
    }

    public function test_patch_excludes_self_but_rejects_moving_into_another_active_lesson(): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        Lesson::factory()->for($lesson->user)->create(['starts_at' => '2026-10-05 08:00:00', 'ends_at' => '2026-10-05 09:00:00']);
        $this->actingAs($lesson->user, 'web')->patchJson($this->url($lesson), [])->assertOk();
        $before = $lesson->getAttributes();
        $this->patchJson($this->url($lesson), ['ends_at' => '2026-10-05T08:30:00Z'])->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->patchJson($this->url($lesson), ['ends_at' => '2026-10-05T08:00:00Z'])->assertOk();
    }

    #[DataProvider('inactiveStatuses')]
    public function test_editing_inactive_lesson_preserves_status_and_does_not_reserve_time(LessonStatus $status): void
    {
        $lesson = Lesson::factory()->create(['status' => $status]);
        Lesson::factory()->for($lesson->user)->create(['starts_at' => '2026-10-06 09:00:00', 'ends_at' => '2026-10-06 10:00:00']);
        $this->actingAs($lesson->user, 'web')->patchJson($this->url($lesson), [
            'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z', 'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', $status->value);
        $this->assertSame($status, $lesson->refresh()->status);
    }

    public static function inactiveStatuses(): array
    {
        return [[LessonStatus::Cancelled], [LessonStatus::Replaced]];
    }

    public function test_delete_soft_deletes_without_status_transition_and_binding_hides_history(): void
    {
        $lesson = Lesson::factory()->create();
        $this->actingAs($lesson->user, 'web')->deleteJson($this->url($lesson))->assertNoContent();
        $this->assertSoftDeleted($lesson);
        $this->assertNull(Lesson::find($lesson->id));
        $this->assertSame(LessonStatus::Active, Lesson::withTrashed()->findOrFail($lesson->id)->status);
        $this->getJson($this->url($lesson))->assertNotFound();
        $this->patchJson($this->url($lesson), ['room' => '302'])->assertNotFound();
        $this->deleteJson($this->url($lesson))->assertNotFound();
        $this->assertDatabaseCount('lessons', 1);
    }
}
