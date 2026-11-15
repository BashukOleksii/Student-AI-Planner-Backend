<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('rest')]
#[Group('mysql')]
class CompactScheduleViewTest extends TestCase
{
    use RefreshDatabase;

    private function lesson(User $user, string $start, string $end, array $extra = []): Lesson
    {
        return Lesson::factory()->for($user)->create(['starts_at' => $start, 'ends_at' => $end, ...$extra]);
    }

    #[DataProvider('invalidDates')]
    public function test_date_and_week_reject_noncanonical_dates(string $date): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['date', 'week'] as $view) {
            $this->getJson('/api/schedule/'.$view.'/'.$date.'?date=2026-10-06')->assertUnprocessable()->assertJsonValidationErrors('date');
        }
    }

    public static function invalidDates(): array
    {
        return [0 => ['2026-1-6']];
    }

    #[DataProvider('emptyViews')]
    public function test_empty_schedules_have_correct_metadata(string $view, string $start, string $end): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->actingAs($user, 'web')->getJson('/api/schedule/'.$view.'/2026-10-07')->assertOk()->assertExactJson(['data' => [], 'meta' => ['timezone' => 'Europe/Kyiv', 'start_date' => $start, 'end_date' => $end]]);
    }

    public static function emptyViews(): array
    {
        return [0 => ['date', '2026-10-07', '2026-10-07']];
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
                $this->actingAs($kyiv, 'web')->getJson('/api/schedule/today?timezone=UTC&date=2000-01-01')->assertOk()->assertJsonPath('meta.start_date', '2026-10-07')->assertJsonPath('meta.end_date', '2026-10-07')->assertJsonPath('data.0.id', $kyivToday->id)->assertJsonCount(1, 'data');
                Auth::forgetGuards();
                $this->actingAs($newYork, 'web')->getJson('/api/schedule/today')->assertOk()->assertJsonPath('meta.start_date', '2026-10-06')->assertJsonPath('data.0.starts_at', '2026-10-06T18:00:00-04:00')->assertJsonPath('data.0.id', $nyToday->id)->assertJsonCount(1, 'data');
            }
        } finally {
            $this->travelBack();
            date_default_timezone_set($oldTimezone);
        }
    }

    public function test_date_uses_overlap_boundaries_and_only_effective_owned_lessons(): void
    {
        $user = User::factory()->create(['timezone' => 'Europe/Kyiv']);
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
        $this->actingAs($user, 'web')->getJson('/api/schedule/date/2026-10-06')->assertOk()->assertJsonPath('meta.timezone', 'Europe/Kyiv')->assertJsonPath('data.*.id', [$into->id, $inside->id, $out->id]);
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
        $this->actingAs($user, 'web')->getJson('/api/schedule/week/2026-10-07')->assertOk()->assertJsonPath('data.*.id', $ids)->assertJsonPath('meta', ['timezone' => 'Europe/Kyiv', 'start_date' => '2026-10-05', 'end_date' => '2026-10-11']);
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
                $this->actingAs($user, 'web')->getJson('/api/schedule/'.$view.'/'.$anchor)->assertOk()->assertJsonPath('data.*.id', [$first->id, $last->id])->assertJsonPath('meta.start_date', $labelStart)->assertJsonPath('meta.end_date', $labelEnd);
            }
        } finally {
            date_default_timezone_set($oldTimezone);
        }
    }

    public static function dstRanges(): array
    {
        return [0 => ['date', '2026-03-29', '2026-03-28 22:00:00', '2026-03-29 21:00:00', '2026-03-29', '2026-03-29']];
    }
}
