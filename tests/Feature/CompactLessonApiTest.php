<?php

namespace Tests\Feature;

use App\Models\AcademicPeriod;
use App\Models\EducationInstitution;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactLessonApiTest extends TestCase
{
    use RefreshDatabase;

    private function values(Subject $subject, array $overrides = []): array
    {
        return array_replace(['subject_id' => $subject->id, 'type' => 'lecture', 'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z'], $overrides);
    }

    private function url(Lesson $lesson): string
    {
        return '/api/lessons/'.$lesson->id;
    }

    public function test_optional_associations_may_have_different_institutions_but_same_user(): void
    {
        $user = User::factory()->create();
        $first = EducationInstitution::factory()->for($user)->create();
        $second = EducationInstitution::factory()->for($user)->create();
        $subject = Subject::factory()->for($user)->for($first)->create();
        $teacher = Teacher::factory()->for($user)->for($second)->create();
        $period = AcademicPeriod::factory()->for($user)->create();
        $this->actingAs($user, 'web')->postJson('/api/lessons', $this->values($subject, ['teacher_id' => $teacher->id, 'academic_period_id' => $period->id, 'room' => str_repeat('R', 100), 'type' => 'practical']))->assertCreated()->assertExactJson(['data' => ['id' => Lesson::sole()->id, 'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'academic_period_id' => $period->id, 'type' => 'practical', 'room' => str_repeat('R', 100), 'starts_at' => '2026-10-06T09:00:00Z', 'ends_at' => '2026-10-06T10:00:00Z', 'status' => 'active', 'replaces_lesson_id' => null, 'replacement_id' => null]]);
    }

    #[DataProvider('lessonTypes')]
    public function test_all_approved_types_are_accepted(string $type): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject, ['type' => $type]))->assertCreated()->assertJsonPath('data.type', $type);
    }

    public static function lessonTypes(): array
    {
        return [0 => ['lecture']];
    }

    public function test_explicit_null_optionals_are_accepted_on_create(): void
    {
        $subject = Subject::factory()->create();
        $this->actingAs($subject->user, 'web')->postJson('/api/lessons', $this->values($subject, ['teacher_id' => null, 'academic_period_id' => null, 'room' => null]))->assertCreated()->assertJsonPath('data.teacher_id', null)->assertJsonPath('data.academic_period_id', null)->assertJsonPath('data.room', null);
    }

    public function test_post_requires_all_nonnullable_fields_and_no_general_index_exists(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/lessons', [])->assertUnprocessable()->assertJsonValidationErrors(['subject_id', 'type', 'starts_at', 'ends_at']);
        $this->getJson('/api/lessons')->assertStatus(405);
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_manual_fields_fail_create_and_patch_without_changes(array $values, string $field): void
    {
        $lesson = Lesson::factory()->create()->refresh();
        $before = $lesson->getAttributes();
        $this->actingAs($lesson->user, 'web')->postJson('/api/lessons', $this->values($lesson->subject, $values))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->patchJson($this->url($lesson), $values)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $lesson->refresh()->getAttributes());
        $this->assertDatabaseCount('lessons', 1);
    }

    public static function invalidFields(): array
    {
        return [0 => [['subject_id' => null], 'subject_id']];
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
        return [0 => ['2026-10-06 09:00:00']];
    }
}
