<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Schedule\ScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScheduleViewTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(User $user, string $start, string $end, array $extra = []): Lesson
    {
        return Lesson::factory()->for($user)->create([
            'starts_at' => $start, 'ends_at' => $end, ...$extra,
        ]);
    }

    public function test_all_schedule_views_require_authentication(): void
    {
        foreach (['today', 'date/2026-10-06', 'week/2026-10-06'] as $path) {
            $this->getJson('/api/schedule/'.$path)->assertUnauthorized();
        }
    }

    #[DataProvider('invalidDates')]
    public function test_date_and_week_reject_noncanonical_dates(string $date): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['date', 'week'] as $view) {
            $this->getJson('/api/schedule/'.$view.'/'.$date.'?date=2026-10-06')
                ->assertUnprocessable()->assertJsonValidationErrors('date');
        }
    }

    public static function invalidDates(): array
    {
        return array_map(fn ($date) => [$date], [
            '2026-1-6', '2026-10-6', '06-10-2026', '2026.10.06', '2026/10/06',
            '2026-02-30', '2026-13-01', '2026-00-01', 'today', '2026-10-06T00:00:00Z', '2026-10-06%20',
        ]);
    }

    #[DataProvider('emptyViews')]
    public function test_empty_schedules_have_correct_metadata(string $view, string $start, string $end): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->actingAs($user, 'web')->getJson('/api/schedule/'.$view.'/2026-10-07')
            ->assertOk()->assertExactJson(['data' => [], 'meta' => [
                'timezone' => 'Europe/Kyiv', 'start_date' => $start, 'end_date' => $end,
            ]]);
    }

    public static function emptyViews(): array
    {
        return [['date', '2026-10-07', '2026-10-07'], ['week', '2026-10-05', '2026-10-11']];
    }

    public function test_today_uses_each_users_local_date_independently_of_server_timezone(): void
    {
        $kyiv = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $newYork = User::factory()->create(['timezone' => 'America/New_York']);
        $kyivToday = $this->lesson($kyiv, '2026-10-06 22:00:00', '2026-10-06 23:00:00');
        $this->lesson($kyiv, '2026-10-06 08:00:00', '2026-10-06 09:00:00');
        $nyToday = $this->lesson($newYork, '2026-10-06 22:00:00', '2026-10-06 23:00:00');
        $this->lesson($newYork, '2026-10-07 08:00:00', '2026-10-07 09:00:00');
        $oldTimezone = date_default_timezone_get();
        $this->travelTo(CarbonImmutable::parse('2026-10-06T22:30:00Z'));
        try {
            foreach (['UTC', 'Pacific/Honolulu', 'Asia/Tokyo'] as $serverTimezone) {
                date_default_timezone_set($serverTimezone);
                Auth::forgetGuards();
                $this->actingAs($kyiv, 'web')->getJson('/api/schedule/today?timezone=UTC&date=2000-01-01')
                    ->assertOk()->assertJsonPath('meta.start_date', '2026-10-07')
                    ->assertJsonPath('meta.end_date', '2026-10-07')->assertJsonPath('data.0.id', $kyivToday->id)->assertJsonCount(1, 'data');
                Auth::forgetGuards();
                $this->actingAs($newYork, 'web')->getJson('/api/schedule/today')
                    ->assertOk()->assertJsonPath('meta.start_date', '2026-10-06')
                    ->assertJsonPath('data.0.starts_at', '2026-10-06T18:00:00-04:00')
                    ->assertJsonPath('data.0.id', $nyToday->id)->assertJsonCount(1, 'data');
            }
        } finally {
            $this->travelBack();
            date_default_timezone_set($oldTimezone);
        }
    }

    public function test_date_uses_overlap_boundaries_and_only_effective_owned_lessons(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        // October 6 local day spans October 5 21:00 UTC to October 6 21:00 UTC.
        $this->lesson($user, '2026-10-05 20:00:00', '2026-10-05 21:00:00');
        $into = $this->lesson($user, '2026-10-05 20:59:59', '2026-10-05 21:00:01');
        $inside = $this->lesson($user, '2026-10-06 08:00:00', '2026-10-06 09:00:00');
        $out = $this->lesson($user, '2026-10-06 20:59:59', '2026-10-06 21:00:01');
        $this->lesson($user, '2026-10-06 21:00:00', '2026-10-06 22:00:00');
        foreach (['cancelled', 'replaced'] as $status) {
            $this->lesson($user, '2026-10-06 10:00:00', '2026-10-06 11:00:00', ['status' => $status]);
        }
        $this->lesson($user, '2026-10-06 12:00:00', '2026-10-06 13:00:00')->delete();
        $foreign = $this->lesson(User::factory()->create(), '2026-10-06 08:00:00', '2026-10-06 09:00:00');
        $before = Lesson::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/2026-10-06?user_id='.$foreign->user_id.'&owner_id='.$foreign->user_id.'&timezone=UTC&date=2000-01-01')
            ->assertOk()->assertJsonPath('meta.timezone', 'Europe/Kyiv')
            ->assertJsonPath('data.*.id', [$into->id, $inside->id, $out->id]);
        $this->assertSame($before, Lesson::withTrashed()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_iso_week_contains_all_seven_days_and_excludes_surrounding_boundaries(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->lesson($user, '2026-10-04 20:00:00', '2026-10-04 21:00:00');
        $ids = [];
        for ($day = 4; $day <= 10; $day++) {
            $date = '2026-10-'.sprintf('%02d', $day);
            $ids[] = $this->lesson($user, $date.' 21:00:00', $date.' 22:00:00')->id;
        }
        $this->lesson($user, '2026-10-11 21:00:00', '2026-10-11 22:00:00');
        $this->actingAs($user, 'web')->getJson('/api/schedule/week/2026-10-07')
            ->assertOk()->assertJsonPath('data.*.id', $ids)->assertJsonPath('meta', [
                'timezone' => 'Europe/Kyiv', 'start_date' => '2026-10-05', 'end_date' => '2026-10-11',
            ]);
        $this->getJson('/api/schedule/week/2026-10-11')->assertOk()->assertJsonPath('data.*.id', $ids);
        $this->getJson('/api/schedule/week/2026-10-05')->assertOk()->assertJsonPath('data.*.id', $ids);
    }

    #[DataProvider('dstRanges')]
    public function test_dst_calendar_ranges_use_true_utc_boundaries(string $view, string $anchor, string $start, string $end, string $labelStart, string $labelEnd, string $timezone = 'Europe/Kyiv'): void
    {
        $user = User::factory()->create(['timezone' => $timezone]);
        $rangeStart = CarbonImmutable::parse($start, 'UTC');
        $rangeEnd = CarbonImmutable::parse($end, 'UTC');
        $sql = fn (CarbonImmutable $time) => $time->format('Y-m-d H:i:s');
        $this->lesson($user, $sql($rangeStart->subHour()), $sql($rangeStart));
        $first = $this->lesson($user, $sql($rangeStart), $sql($rangeStart->addSecond()));
        $last = $this->lesson($user, $sql($rangeEnd->subSecond()), $sql($rangeEnd));
        $this->lesson($user, $sql($rangeEnd), $sql($rangeEnd->addHour()));
        $oldTimezone = date_default_timezone_get();
        try {
            foreach (['UTC', 'America/Los_Angeles'] as $zone) {
                date_default_timezone_set($zone);
                $this->actingAs($user, 'web')->getJson('/api/schedule/'.$view.'/'.$anchor)
                    ->assertOk()->assertJsonPath('data.*.id', [$first->id, $last->id])
                    ->assertJsonPath('meta.start_date', $labelStart)->assertJsonPath('meta.end_date', $labelEnd);
            }
        } finally {
            date_default_timezone_set($oldTimezone);
        }
    }

    public static function dstRanges(): array
    {
        return [
            ['date', '2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', '2026-03-29', '2026-03-29'],
            ['date', '2026-10-25', '2026-10-24 21:00:00', '2026-10-25 22:00:00', '2026-10-25', '2026-10-25'],
            ['week', '2026-03-29', '2026-03-22 22:00:00', '2026-03-29 21:00:00', '2026-03-23', '2026-03-29'],
            ['week', '2026-10-25', '2026-10-18 21:00:00', '2026-10-25 22:00:00', '2026-10-19', '2026-10-25'],
            ['date', '2018-11-04', '2018-11-04 03:00:00', '2018-11-05 02:00:00', '2018-11-04', '2018-11-04', 'America/Sao_Paulo'],
            ['week', '2018-11-04', '2018-10-29 03:00:00', '2018-11-05 02:00:00', '2018-10-29', '2018-11-04', 'America/Sao_Paulo'],
        ];
    }

    #[DataProvider('dstInstants')]
    public function test_presentation_offsets_follow_each_instant(string $date, string $firstUtc, string $secondUtc, string $firstLocal, string $secondLocal): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        foreach ([$firstUtc, $secondUtc] as $instant) {
            $start = CarbonImmutable::parse($instant, 'UTC');
            $this->lesson($user, $start->format('Y-m-d H:i:s'), $start->addMinute()->format('Y-m-d H:i:s'));
        }
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/'.$date)->assertOk()
            ->assertJsonPath('data.0.starts_at', $firstLocal)->assertJsonPath('data.1.starts_at', $secondLocal);
    }

    public static function dstInstants(): array
    {
        return [
            ['2026-03-29', '2026-03-29 00:30:00', '2026-03-29 01:30:00', '2026-03-29T02:30:00+02:00', '2026-03-29T04:30:00+03:00'],
            ['2026-10-25', '2026-10-25 00:30:00', '2026-10-25 01:30:00', '2026-10-25T03:30:00+03:00', '2026-10-25T03:30:00+02:00'],
        ];
    }

    public function test_exact_presentation_fields_and_canonical_detail_remains_utc(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $subject = Subject::factory()->for($user)->create(['name' => 'Software Design', 'code' => 'SD', 'color' => '#3366FF']);
        $teacher = Teacher::factory()->for($user)->create(['name' => 'John Doe']);
        $original = $this->lesson($user, '2026-10-06 07:30:00', '2026-10-06 09:00:00', ['status' => 'replaced']);
        $lesson = $this->lesson($user, '2026-10-06 07:30:00', '2026-10-06 09:00:00', [
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'room' => '301', 'replaces_lesson_id' => $original->id,
        ]);
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/2026-10-06')->assertOk()->assertExactJson([
            'data' => [[
                'id' => $lesson->id, 'subject' => ['id' => $subject->id, 'name' => 'Software Design', 'code' => 'SD', 'color' => '#3366FF'],
                'teacher' => ['id' => $teacher->id, 'name' => 'John Doe'], 'type' => 'lecture', 'room' => '301',
                'starts_at' => '2026-10-06T10:30:00+03:00', 'ends_at' => '2026-10-06T12:00:00+03:00', 'replaces_lesson_id' => $original->id,
            ]],
            'meta' => ['timezone' => 'Europe/Kyiv', 'start_date' => '2026-10-06', 'end_date' => '2026-10-06'],
        ]);
        $this->getJson('/api/lessons/'.$lesson->id)->assertOk()->assertJsonPath('data.starts_at', '2026-10-06T07:30:00Z');
        $lesson->update(['teacher_id' => null, 'room' => null]);
        $subject->update(['code' => null, 'color' => null]);
        $this->getJson('/api/schedule/date/2026-10-06')->assertOk()->assertJsonPath('data.0.teacher', null)
            ->assertJsonPath('data.0.subject.code', null)->assertJsonPath('data.0.subject.color', null)->assertJsonPath('data.0.room', null);
    }

    public function test_malformed_foreign_relations_are_masked_without_read_side_effects(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $foreign = User::factory()->create();
        $subject = Subject::factory()->for($foreign)->create(['name' => 'PRIVATE SUBJECT', 'code' => 'PRIVATE', 'color' => '#ABCDEF']);
        $teacher = Teacher::factory()->for($foreign)->create(['name' => 'PRIVATE TEACHER']);
        $original = $this->lesson($foreign, '2026-10-06 06:00:00', '2026-10-06 07:00:00', ['status' => 'replaced']);
        $lesson = $this->lesson($user, '2026-10-06 06:00:00', '2026-10-06 07:00:00', [
            'subject_id' => $subject->id, 'teacher_id' => $teacher->id, 'replaces_lesson_id' => $original->id,
        ]);
        $before = Lesson::withTrashed()->orderBy('id')->get()->map->getAttributes()->all();
        $this->travelTo(CarbonImmutable::parse('2026-10-06T08:00:00Z'));
        try {
            $this->actingAs($user, 'web');
            foreach (['today', 'date/2026-10-06', 'week/2026-10-06'] as $view) {
                $response = $this->getJson('/api/schedule/'.$view)->assertOk()->assertJsonCount(1, 'data')
                    ->assertJsonPath('data.0.id', $lesson->id)->assertJsonPath('data.0.subject', null)
                    ->assertJsonPath('data.0.teacher', null)->assertJsonPath('data.0.replaces_lesson_id', null);
                $this->assertSame([
                    'id' => $lesson->id, 'subject' => null, 'teacher' => null, 'type' => 'lecture', 'room' => null,
                    'starts_at' => '2026-10-06T06:00:00+00:00', 'ends_at' => '2026-10-06T07:00:00+00:00', 'replaces_lesson_id' => null,
                ], $response->json('data.0'));
            }
        } finally {
            $this->travelBack();
        }
        $this->assertSame($before, Lesson::withTrashed()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_schedule_tracks_actual_cancellation_replacement_and_revert_lifecycle(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $original = $this->lesson($user, '2026-10-06 09:00:00', '2026-10-06 10:00:00');
        $this->actingAs($user, 'web');
        $url = '/api/schedule/date/2026-10-06';
        $this->getJson($url)->assertOk()->assertJsonPath('data.*.id', [$original->id]);
        $response = $this->postJson('/api/lessons/'.$original->id.'/replacement', [
            'subject_id' => $original->subject_id, 'type' => 'practical',
            'starts_at' => '2026-10-06T11:00:00Z', 'ends_at' => '2026-10-06T12:00:00Z',
        ])->assertCreated();
        $replacementId = $response->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.*.id', [$replacementId]);
        $this->postJson('/api/lessons/'.$replacementId.'/cancel')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data', []);
        $this->deleteJson('/api/lessons/'.$replacementId)->assertNoContent();
        $this->getJson($url)->assertOk()->assertJsonPath('data.*.id', [$original->id]);
        $this->postJson('/api/lessons/'.$original->id.'/cancel')->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('data', []);
    }

    public function test_ordering_uses_start_end_and_id_and_relations_are_eager_loaded(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $long = $this->lesson($user, '2026-10-06 10:00:00', '2026-10-06 12:00:00');
        $firstTie = $this->lesson($user, '2026-10-06 10:00:00', '2026-10-06 11:00:00');
        $early = $this->lesson($user, '2026-10-06 09:00:00', '2026-10-06 10:00:00');
        $secondTie = $this->lesson($user, '2026-10-06 10:00:00', '2026-10-06 11:00:00');
        $expected = [$early->id, $firstTie->id, $secondTie->id, $long->id];
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/2026-10-06')->assertOk()->assertJsonPath('data.*.id', $expected);
        $schedule = app(ScheduleService::class)->date($user, '2026-10-06');
        foreach ($schedule['lessons'] as $lesson) {
            foreach (['subject', 'teacher', 'replacedLesson'] as $relation) {
                $this->assertTrue($lesson->relationLoaded($relation));
            }
        }
    }

    public function test_deleted_original_is_not_disclosed_in_schedule_replacement_marker(): void
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        $original = $this->lesson($user, '2026-10-06 06:00:00', '2026-10-06 07:00:00', ['status' => 'replaced']);
        $original->delete();
        $this->lesson($user, '2026-10-06 06:00:00', '2026-10-06 07:00:00', ['replaces_lesson_id' => $original->id]);
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/2026-10-06')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.replaces_lesson_id', null);
    }

    public function test_timezone_skipped_calendar_date_has_no_instant_membership(): void
    {
        $user = User::factory()->create(['timezone' => 'Pacific/Apia']);
        // The 2011 date-line change skipped December 30 entirely, giving a zero-length range.
        $this->lesson($user, '2011-12-30 09:00:00', '2011-12-30 11:00:00');
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/2011-12-30')->assertOk()->assertExactJson([
            'data' => [], 'meta' => ['timezone' => 'Pacific/Apia', 'start_date' => '2011-12-30', 'end_date' => '2011-12-30'],
        ]);
    }
}
