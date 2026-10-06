<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Schedule\LessonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LessonLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function url(Lesson $lesson, string $action = ''): string
    {
        return '/api/lessons/'.$lesson->id.$action;
    }

    private function values(Lesson $lesson, array $overrides = []): array
    {
        return array_replace([
            'subject_id' => $lesson->subject_id, 'type' => 'lecture',
            'starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:30:00Z',
        ], $overrides);
    }

    private function replace(Lesson $original, array $overrides = []): Lesson
    {
        $response = $this->postJson($this->url($original, '/replacement'), $this->values($original, $overrides))->assertCreated();

        return Lesson::findOrFail($response->json('data.id'));
    }

    public function test_action_routes_require_authentication(): void
    {
        $lesson = Lesson::factory()->create();
        $this->postJson($this->url($lesson, '/cancel'))->assertUnauthorized();
        $this->postJson($this->url($lesson, '/replacement'), $this->values($lesson))->assertUnauthorized();
    }

    public function test_foreign_actions_are_hidden_before_validation(): void
    {
        $lesson = Lesson::factory()->create();
        $before = $lesson->refresh()->getAttributes();
        $this->actingAs(User::factory()->create(), 'web');
        $this->postJson($this->url($lesson, '/cancel'))->assertNotFound();
        $this->postJson($this->url($lesson, '/replacement'), $this->values($lesson))->assertNotFound();
        $this->postJson($this->url($lesson, '/replacement'), ['starts_at' => 'invalid'])->assertNotFound();
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    public function test_cancellation_frees_time_and_is_not_idempotent(): void
    {
        $lesson = Lesson::factory()->create();
        $this->actingAs($lesson->user, 'web');
        $this->postJson($this->url($lesson, '/cancel'))->assertOk()->assertJsonPath('data.status', 'cancelled');
        $before = $lesson->refresh()->getAttributes();
        $this->postJson($this->url($lesson, '/cancel'))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->postJson('/api/lessons', $this->values($lesson))->assertCreated();
    }

    public function test_replacement_sets_lifecycle_and_ignores_internal_payload(): void
    {
        $original = Lesson::factory()->create();
        $teacher = Teacher::factory()->for($original->user)->create();
        $period = AcademicPeriod::factory()->for($original->user)->create();
        $foreign = Lesson::factory()->create();
        $this->actingAs($original->user, 'web');
        $replacement = $this->replace($original, [
            'teacher_id' => $teacher->id, 'academic_period_id' => $period->id, 'room' => '301',
            'user_id' => $foreign->user_id, 'id' => $foreign->id, 'status' => 'cancelled',
            'replaces_lesson_id' => $foreign->id, 'schedule_import_batch_id' => 999999,
            'import_fingerprint' => str_repeat('b', 64), 'deleted_at' => '2000-01-01',
        ]);
        $this->assertSame($original->user_id, $replacement->user_id);
        $this->assertSame($original->id, $replacement->replaces_lesson_id);
        $this->assertSame(LessonStatus::Active, $replacement->status);
        $this->assertSame(LessonStatus::Replaced, $original->refresh()->status);
        $this->assertNull($replacement->schedule_import_batch_id);
        $this->assertNull($replacement->import_fingerprint);
        $this->assertNull($replacement->deleted_at);
        $this->getJson($this->url($replacement))->assertOk()->assertExactJson(['data' => [
            'id' => $replacement->id, 'academic_period_id' => $period->id, 'subject_id' => $original->subject_id,
            'teacher_id' => $teacher->id, 'type' => 'lecture', 'room' => '301',
            'starts_at' => '2026-10-05T06:00:00Z', 'ends_at' => '2026-10-05T07:30:00Z',
            'status' => 'active', 'replaces_lesson_id' => $original->id, 'replacement_id' => null,
        ]]);
        $this->getJson($this->url($original))->assertOk()->assertJsonPath('data.replacement_id', $replacement->id)
            ->assertJsonPath('data.replaces_lesson_id', null)->assertJsonPath('data.status', 'replaced');
        $this->postJson($this->url($original, '/cancel'))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($this->url($original, '/replacement'), $this->values($original))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->postJson($this->url($replacement, '/replacement'), $this->values($replacement))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame(2, $original->user->lessons()->count());
    }

    public function test_cancelled_original_cannot_be_replaced(): void
    {
        $original = Lesson::factory()->create(['status' => LessonStatus::Cancelled]);
        $before = $original->refresh()->getAttributes();
        $this->actingAs($original->user, 'web')->postJson($this->url($original, '/replacement'), $this->values($original))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($before, $original->refresh()->getAttributes());
        $this->assertSame(1, Lesson::count());
    }

    #[DataProvider('replacementStates')]
    public function test_delete_replacement_restores_original_and_history_can_be_reused(bool $cancel): void
    {
        $original = Lesson::factory()->create();
        $this->actingAs($original->user, 'web');
        $replacement = $this->replace($original, ['room' => 'old']);
        if ($cancel) {
            $this->postJson($this->url($replacement, '/cancel'))->assertOk()->assertJsonPath('data.status', 'cancelled');
            $this->assertSame(LessonStatus::Replaced, $original->refresh()->status);
        }
        $before = $original->refresh()->getAttributes();
        $this->deleteJson($this->url($original))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($before, $original->refresh()->getAttributes());
        $this->deleteJson($this->url($replacement))->assertNoContent()->assertContent('');
        $this->assertSoftDeleted($replacement);
        $this->assertNotNull(Lesson::withTrashed()->find($replacement->id));
        $this->assertSame(LessonStatus::Active, $original->refresh()->status);
        $this->getJson($this->url($replacement))->assertNotFound();
        $this->getJson($this->url($original))->assertOk()->assertJsonPath('data.replacement_id', null);
        $restored = $this->replace($original, ['room' => 'new', 'type' => 'exam']);
        $this->assertSame($replacement->id, $restored->id);
        $this->assertSame('new', $restored->room);
        $this->assertSame('exam', $restored->type->value);
        $this->assertSame(LessonStatus::Active, $restored->status);
        $this->assertNull($restored->deleted_at);
        $this->assertSame(2, Lesson::withTrashed()->count());
        $this->assertSame(LessonStatus::Replaced, $original->refresh()->status);
        $this->deleteJson($this->url($restored))->assertNoContent();
        $this->deleteJson($this->url($original))->assertNoContent();
        $this->assertSoftDeleted($original);
    }

    public static function replacementStates(): array
    {
        return [[false], [true]];
    }

    public function test_revert_conflict_rolls_back_both_rows(): void
    {
        $original = Lesson::factory()->create();
        $this->actingAs($original->user, 'web');
        $replacement = $this->replace($original, ['starts_at' => '2026-10-05T09:00:00Z', 'ends_at' => '2026-10-05T10:00:00Z']);
        $this->postJson('/api/lessons', $this->values($original))->assertCreated();
        $originalBefore = $original->refresh()->getAttributes();
        $replacementBefore = $replacement->refresh()->getAttributes();
        $this->deleteJson($this->url($replacement))->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertSame($originalBefore, $original->refresh()->getAttributes());
        $this->assertSame($replacementBefore, $replacement->refresh()->getAttributes());
    }

    public function test_generic_patch_preserves_lifecycle_and_checks_active_conflicts(): void
    {
        $original = Lesson::factory()->create();
        $this->actingAs($original->user, 'web');
        $replacement = $this->replace($original);
        $this->patchJson($this->url($replacement), ['room' => 'new', 'status' => 'cancelled', 'replaces_lesson_id' => null])
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.replaces_lesson_id', $original->id);
        Lesson::factory()->for($original->user)->create(['starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 10:00:00']);
        $before = $replacement->refresh()->getAttributes();
        $this->patchJson($this->url($replacement), ['starts_at' => '2026-10-05T09:00:00Z', 'ends_at' => '2026-10-05T10:00:00Z'])
            ->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertSame($before, $replacement->refresh()->getAttributes());
        $this->postJson($this->url($replacement, '/cancel'))->assertOk();
        $this->patchJson($this->url($replacement), ['room' => 'cancelled edit', 'status' => 'active'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    #[DataProvider('conflictingStates')]
    public function test_replacement_conflicts_follow_effective_schedule(string $state, bool $conflicts): void
    {
        $original = Lesson::factory()->create();
        $other = Lesson::factory()->for($original->user)->create([
            'starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 10:00:00',
            'status' => in_array($state, ['cancelled', 'replaced']) ? $state : 'active',
        ]);
        if ($state === 'deleted') {
            $other->delete();
        }
        if ($state === 'foreign') {
            $other->update(['user_id' => User::factory()->create()->id]);
        }
        $this->actingAs($original->user, 'web');
        $before = $original->refresh()->getAttributes();
        $response = $this->postJson($this->url($original, '/replacement'), $this->values($original, [
            'starts_at' => '2026-10-05T09:00:00Z', 'ends_at' => '2026-10-05T10:00:00Z',
        ]));
        if ($conflicts) {
            $response->assertUnprocessable()->assertJsonValidationErrors('schedule');
            $this->assertSame($before, $original->refresh()->getAttributes());
            $this->assertNull($original->replacement);
        } else {
            $response->assertCreated();
        }
    }

    public static function conflictingStates(): array
    {
        return [['active', true], ['cancelled', false], ['replaced', false], ['deleted', false], ['foreign', false]];
    }

    public function test_replacement_can_be_adjacent_to_other_active_lesson(): void
    {
        $original = Lesson::factory()->create();
        Lesson::factory()->for($original->user)->create(['starts_at' => '2026-10-05 07:30:00', 'ends_at' => '2026-10-05 08:00:00']);
        $this->actingAs($original->user, 'web');
        $this->replace($original);
    }

    #[DataProvider('invalidInputs')]
    public function test_replacement_reuses_strict_manual_validation(array $values, string $key): void
    {
        $original = Lesson::factory()->create();
        $before = $original->refresh()->getAttributes();
        $this->actingAs($original->user, 'web')->postJson($this->url($original, '/replacement'), $this->values($original, $values))
            ->assertUnprocessable()->assertJsonValidationErrors($key);
        $this->assertSame($before, $original->refresh()->getAttributes());
        $this->assertSame(1, Lesson::withTrashed()->count());
    }

    public static function invalidInputs(): array
    {
        return [
            [['subject_id' => null], 'subject_id'], [['subject_id' => '1'], 'subject_id'],
            [['type' => 'unknown'], 'type'], [['room' => str_repeat('a', 101)], 'room'],
            [['starts_at' => '2026-10-05 06:00:00'], 'starts_at'],
            [['starts_at' => '2026-10-05T06:00:00'], 'starts_at'],
            [['starts_at' => '2026-10-05T06:00:00+00:00'], 'starts_at'],
            [['starts_at' => '2026-10-05T06:00:00.000Z'], 'starts_at'],
            [['starts_at' => '2026-10-05T07:30:00Z'], 'ends_at'],
            [['starts_at' => '2026-10-05T08:00:00Z'], 'ends_at'],
        ];
    }

    #[DataProvider('associations')]
    public function test_missing_and_foreign_associations_have_identical_errors(string $field, string $model): void
    {
        $original = Lesson::factory()->create();
        $foreign = $model::factory()->create();
        $this->actingAs($original->user, 'web');
        $before = $original->refresh()->getAttributes();
        $foreignResponse = $this->postJson($this->url($original, '/replacement'), $this->values($original, [$field => $foreign->id]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $missingResponse = $this->postJson($this->url($original, '/replacement'), $this->values($original, [$field => 999999]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($foreignResponse->json(), $missingResponse->json());
        $this->assertSame($before, $original->refresh()->getAttributes());
        $this->assertSame(1, Lesson::count());
    }

    public static function associations(): array
    {
        return [['subject_id', Subject::class], ['teacher_id', Teacher::class], ['academic_period_id', AcademicPeriod::class]];
    }

    public function test_owned_replacement_of_foreign_original_is_masked_and_cannot_mutate_lifecycle(): void
    {
        $original = Lesson::factory()->create(['status' => 'replaced']);
        $replacement = Lesson::factory()->create(['replaces_lesson_id' => $original->id]);
        $originalBefore = $original->refresh()->getAttributes();
        $replacementBefore = $replacement->refresh()->getAttributes();
        $this->actingAs($replacement->user, 'web')->getJson($this->url($replacement))
            ->assertOk()->assertJsonPath('data.replaces_lesson_id', null);
        $this->deleteJson($this->url($replacement))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->postJson($this->url($replacement, '/cancel'))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->postJson($this->url($replacement, '/replacement'), $this->values($replacement))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($originalBefore, $original->refresh()->getAttributes());
        $this->assertSame($replacementBefore, $replacement->refresh()->getAttributes());
    }

    #[DataProvider('replacementStates')]
    public function test_foreign_child_is_masked_and_never_overwritten_or_restored(bool $deleted): void
    {
        $original = Lesson::factory()->create();
        $foreign = Lesson::factory()->create(['replaces_lesson_id' => $original->id]);
        if ($deleted) {
            $foreign->delete();
        }
        $originalBefore = $original->refresh()->getAttributes();
        $foreignBefore = $foreign->refresh()->getAttributes();
        $this->actingAs($original->user, 'web')->getJson($this->url($original))
            ->assertOk()->assertJsonPath('data.replacement_id', null);
        $response = $this->postJson($this->url($original, '/replacement'), $this->values($original))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame(['The lesson replacement operation is not available.'], $response->json('errors.replacement'));
        $this->deleteJson($this->url($original))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($originalBefore, $original->refresh()->getAttributes());
        $this->assertSame($foreignBefore, $foreign->refresh()->getAttributes());
    }

    public function test_self_reference_is_rejected_without_mutation(): void
    {
        $lesson = Lesson::factory()->create();
        $lesson->update(['replaces_lesson_id' => $lesson->id]);
        $before = $lesson->refresh()->getAttributes();
        $this->actingAs($lesson->user, 'web');
        $this->postJson($this->url($lesson, '/replacement'), $this->values($lesson))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->deleteJson($this->url($lesson))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->postJson($this->url($lesson, '/cancel'))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($before, $lesson->refresh()->getAttributes());
    }

    #[DataProvider('invalidOriginals')]
    public function test_revert_rejects_invalid_original_lifecycle(string $state): void
    {
        $original = Lesson::factory()->create(['status' => $state === 'deleted' ? 'replaced' : $state]);
        $replacement = Lesson::factory()->for($original->user)->create(['replaces_lesson_id' => $original->id]);
        if ($state === 'deleted') {
            $original->delete();
        }
        $a = $original->refresh()->getAttributes();
        $b = $replacement->refresh()->getAttributes();
        $this->actingAs($original->user, 'web')->deleteJson($this->url($replacement))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        if ($state === 'deleted') {
            $this->getJson($this->url($replacement))->assertOk()->assertJsonPath('data.replaces_lesson_id', null);
        }
        $this->assertSame($a, $original->refresh()->getAttributes());
        $this->assertSame($b, $replacement->refresh()->getAttributes());
    }

    public static function invalidOriginals(): array
    {
        return [['active'], ['cancelled'], ['deleted']];
    }

    public function test_legacy_chain_cannot_be_reverted_or_extended(): void
    {
        $original = Lesson::factory()->create(['status' => 'replaced']);
        $replacement = Lesson::factory()->for($original->user)->create(['replaces_lesson_id' => $original->id, 'status' => 'replaced']);
        $child = Lesson::factory()->for($original->user)->create(['replaces_lesson_id' => $replacement->id]);
        $before = [$original->refresh()->getAttributes(), $replacement->refresh()->getAttributes(), $child->refresh()->getAttributes()];
        $this->actingAs($original->user, 'web')->deleteJson($this->url($child))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->deleteJson($this->url($replacement))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->postJson($this->url($child, '/replacement'), $this->values($child))->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($before, [$original->refresh()->getAttributes(), $replacement->refresh()->getAttributes(), $child->refresh()->getAttributes()]);
    }

    #[DataProvider('importMetadata')]
    public function test_import_owned_history_is_never_converted_to_manual_replacement(string $field): void
    {
        $original = Lesson::factory()->create();
        $period = AcademicPeriod::factory()->for($original->user)->create();
        $batch = ScheduleImportBatch::factory()->for($period)->create();
        $history = Lesson::factory()->for($original->user)->create([
            'replaces_lesson_id' => $original->id, 'academic_period_id' => $period->id,
            $field => $field === 'import_fingerprint' ? str_repeat('a', 64) : $batch->id,
        ]);
        $history->delete();
        $a = $original->refresh()->getAttributes();
        $b = $history->refresh()->getAttributes();
        $this->actingAs($original->user, 'web')->postJson($this->url($original, '/replacement'), $this->values($original))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($a, $original->refresh()->getAttributes());
        $this->assertSame($b, $history->refresh()->getAttributes());
        $this->assertSame(2, Lesson::withTrashed()->count());
    }

    public static function importMetadata(): array
    {
        return [['schedule_import_batch_id'], ['import_fingerprint']];
    }

    public function test_current_child_blocks_replacement_even_if_original_status_is_malformed(): void
    {
        $original = Lesson::factory()->create();
        $child = Lesson::factory()->for($original->user)->create(['replaces_lesson_id' => $original->id]);
        $before = [$original->refresh()->getAttributes(), $child->refresh()->getAttributes()];
        $this->actingAs($original->user, 'web')->postJson($this->url($original, '/replacement'), $this->values($original))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($before, [$original->refresh()->getAttributes(), $child->refresh()->getAttributes()]);
    }

    public function test_restored_replacement_uses_complete_post_values_and_clears_omitted_optionals(): void
    {
        $original = Lesson::factory()->create();
        $teacher = Teacher::factory()->for($original->user)->create();
        $period = AcademicPeriod::factory()->for($original->user)->create();
        $this->actingAs($original->user, 'web');
        $old = $this->replace($original, ['teacher_id' => $teacher->id, 'academic_period_id' => $period->id, 'room' => 'old']);
        $this->deleteJson($this->url($old))->assertNoContent();
        $new = $this->replace($original);
        $this->assertSame($old->id, $new->id);
        $this->assertNull($new->teacher_id);
        $this->assertNull($new->academic_period_id);
        $this->assertNull($new->room);
    }

    public function test_failed_restore_conflict_leaves_history_deleted_and_original_active(): void
    {
        $original = Lesson::factory()->create();
        $this->actingAs($original->user, 'web');
        $history = $this->replace($original);
        $this->deleteJson($this->url($history))->assertNoContent();
        Lesson::factory()->for($original->user)->create(['starts_at' => '2026-10-05 09:00:00', 'ends_at' => '2026-10-05 10:00:00']);
        $before = [$original->refresh()->getAttributes(), $history->refresh()->getAttributes()];
        $this->postJson($this->url($original, '/replacement'), $this->values($original, [
            'starts_at' => '2026-10-05T09:00:00Z', 'ends_at' => '2026-10-05T10:00:00Z',
        ]))->assertUnprocessable()->assertJsonValidationErrors('schedule');
        $this->assertSame($before, [$original->refresh()->getAttributes(), $history->refresh()->getAttributes()]);
        $this->assertSame(3, Lesson::withTrashed()->count());
    }

    public function test_invalid_historical_replacement_status_cannot_be_restored(): void
    {
        $original = Lesson::factory()->create();
        $history = Lesson::factory()->for($original->user)->create(['replaces_lesson_id' => $original->id, 'status' => 'replaced']);
        $history->delete();
        $before = [$original->refresh()->getAttributes(), $history->refresh()->getAttributes()];
        $this->actingAs($original->user, 'web')->postJson($this->url($original, '/replacement'), $this->values($original))
            ->assertUnprocessable()->assertJsonValidationErrors('replacement');
        $this->assertSame($before, [$original->refresh()->getAttributes(), $history->refresh()->getAttributes()]);
    }

    public function test_revert_does_not_activate_legacy_foreign_associations(): void
    {
        $original = Lesson::factory()->create(['status' => 'replaced', 'subject_id' => Subject::factory()->create()->id]);
        $replacement = Lesson::factory()->for($original->user)->create(['replaces_lesson_id' => $original->id]);
        $before = [$original->refresh()->getAttributes(), $replacement->refresh()->getAttributes()];
        $this->actingAs($original->user, 'web')->deleteJson($this->url($replacement))
            ->assertUnprocessable()->assertJsonValidationErrors('subject_id');
        $this->assertSame($before, [$original->refresh()->getAttributes(), $replacement->refresh()->getAttributes()]);
    }

    public function test_deleted_route_targets_cannot_receive_lifecycle_actions(): void
    {
        $original = Lesson::factory()->create();
        $original->delete();
        $before = $original->refresh()->getAttributes();
        $this->actingAs($original->user, 'web')->postJson($this->url($original, '/cancel'))->assertNotFound();
        $this->postJson($this->url($original, '/replacement'), $this->values($original))->assertNotFound();
        $this->assertSame($before, $original->refresh()->getAttributes());
    }

    public function test_service_uses_latest_state_for_lifecycle_preconditions(): void
    {
        $original = Lesson::factory()->create();
        $stale = clone $original;
        $original->update(['status' => 'cancelled']);
        $this->expectException(ValidationException::class);
        app(LessonService::class)->createReplacement($original->user, $stale, $this->values($stale));
    }
}
