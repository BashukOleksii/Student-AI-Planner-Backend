<?php

namespace Tests\Feature;

use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

#[Group('ai')]
#[Group('mysql')]
class CompactAiStudyAvailabilityToolTest extends TestCase
{
    use DatabaseTruncation, InteractsWithAiResponses;

    private const TOOL = 'list_study_availability_windows';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    private function window(User $owner, array $values = []): StudyAvailabilityWindow
    {
        return StudyAvailabilityWindow::factory()->for($owner)->create(['day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '20:00:00', ...$values]);
    }

    private function data(User $owner, array $arguments = []): array
    {
        $result = app(ToolExecutor::class)->execute($owner, self::TOOL, $arguments);
        $this->assertTrue($result['ok'], json_encode($result));
        $this->assertSame(self::TOOL, $result['tool']);
        $this->assertSame($result, json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR));

        return $result['data'];
    }

    public function test_no_arguments_return_exact_local_fields_and_persisted_owner_timezone(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $window = $this->window($owner, ['starts_at' => '16:00:01', 'ends_at' => '24:00:00']);
        $foreign = User::factory()->create(['timezone' => 'America/New_York']);
        $this->window($foreign);
        $this->actingAs($owner, 'web');
        $owner->timezone = 'UTC';
        $this->assertSame(['windows' => [['id' => $window->id, 'day_of_week' => 1, 'starts_at' => '16:00:01', 'ends_at' => '24:00:00']], 'meta' => ['timezone' => 'Europe/Kyiv', 'current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1]], $this->data($owner));
        $this->assertSame('UTC', $owner->timezone);
        $this->assertSame($owner->id, auth()->id());
    }

    public function test_filtering_uses_iso_weekdays_and_counts_only_owned_matches(): void
    {
        $owner = User::factory()->create();
        $monday = $this->window($owner);
        $sunday = $this->window($owner, ['day_of_week' => 7, 'starts_at' => '00:00:00']);
        $this->window(User::factory()->create(), ['day_of_week' => 7]);
        foreach ([1 => $monday, 7 => $sunday] as $weekday => $window) {
            $data = $this->data($owner, ['day_of_week' => $weekday]);
            $this->assertSame([$window->id], array_column($data['windows'], 'id'));
            $this->assertSame(1, $data['meta']['total']);
        }
        $this->assertSame([], $this->data($owner, ['day_of_week' => 2])['windows']);
    }

    public function test_pagination_orders_by_weekday_start_and_id_stably(): void
    {
        $owner = User::factory()->create(['timezone' => 'UTC']);
        $last = $this->window($owner, ['day_of_week' => 7]);
        $later = $this->window($owner, ['day_of_week' => 2, 'starts_at' => '18:00:00']);
        $first = $this->window($owner, ['day_of_week' => 2, 'starts_at' => '08:00:00', 'ends_at' => '09:00:00']);
        $second = $this->window($owner, ['day_of_week' => 2, 'starts_at' => '08:00:00', 'ends_at' => '10:00:00']);
        $expected = [$first->id, $second->id, $later->id, $last->id];
        $this->assertSame($expected, array_column($this->data($owner)['windows'], 'id'));
        $page = $this->data($owner, ['page' => 2, 'per_page' => 2]);
        $this->assertSame(array_slice($expected, 2), array_column($page['windows'], 'id'));
        $this->assertSame(['timezone' => 'UTC', 'current_page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2], $page['meta']);
        $this->assertSame($page, $this->data($owner, ['page' => 2, 'per_page' => 2]));
        $this->assertSame([$second->id], array_column($this->data($owner, ['day_of_week' => 2, 'page' => 2, 'per_page' => 1])['windows'], 'id'));
        $empty = $this->data($owner, ['page' => 99]);
        $this->assertSame([], $empty['windows']);
        $this->assertSame(['timezone' => 'UTC', 'current_page' => 99, 'per_page' => 20, 'total' => 4, 'last_page' => 1], $empty['meta']);
        $this->assertSame([], $this->data($owner, ['page' => PHP_INT_MAX, 'per_page' => 1])['windows']);
    }

    public function test_output_is_bounded_with_accurate_total_and_no_silent_truncation(): void
    {
        $owner = User::factory()->create();
        for ($second = 0; $second < 51; $second++) {
            $this->window($owner, ['starts_at' => sprintf('00:00:%02d', $second), 'ends_at' => sprintf('00:00:%02d', $second + 1)]);
        }
        $default = $this->data($owner);
        $this->assertCount(20, $default['windows']);
        $this->assertSame(51, $default['meta']['total']);
        $this->assertSame(3, $default['meta']['last_page']);
        $maximum = $this->data($owner, ['per_page' => 50]);
        $this->assertCount(50, $maximum['windows']);
        $this->assertSame(51, $maximum['meta']['total']);
        $this->assertSame(2, $maximum['meta']['last_page']);
        $last = $this->data($owner, ['page' => 2, 'per_page' => 50]);
        $this->assertCount(1, $last['windows']);
        $this->assertSame('00:00:50', $last['windows'][0]['starts_at']);
    }

    public static function invalidArguments(): array
    {
        return ['day_of_week malformed 0' => [['day_of_week' => null]]];
    }

    #[DataProvider('invalidArguments')]
    public function test_invalid_arguments_are_sanitized_before_database_dispatch(array $arguments): void
    {
        $owner = User::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $result = app(ToolExecutor::class)->execute($owner, self::TOOL, $arguments);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame(['ok' => false, 'tool' => self::TOOL, 'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']], $result);
        $this->assertSame([], $queries);
    }
}
