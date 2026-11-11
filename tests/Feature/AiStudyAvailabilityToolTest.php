<?php

namespace Tests\Feature;

use App\Models\AiConversation;
use App\Models\StudyAvailabilityWindow;
use App\Models\User;
use App\Services\AI\Tools\ToolExecutor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithAiResponses;
use Tests\TestCase;

class AiStudyAvailabilityToolTest extends TestCase
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
        return StudyAvailabilityWindow::factory()->for($owner)->create([
            'day_of_week' => 1, 'starts_at' => '16:00:00', 'ends_at' => '20:00:00', ...$values,
        ]);
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
        $this->actingAs($foreign, 'web');
        $owner->timezone = 'UTC';
        $this->assertSame([
            'windows' => [['id' => $window->id, 'day_of_week' => 1, 'starts_at' => '16:00:01', 'ends_at' => '24:00:00']],
            'meta' => ['timezone' => 'Europe/Kyiv', 'current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
        ], $this->data($owner));
        $this->assertSame('UTC', $owner->timezone);
        $this->assertSame($foreign->id, auth()->id());
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
        // Existing persisted rows can tie on starts_at; reads retain both identities deterministically.
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

    public function test_empty_availability_does_not_expose_foreign_windows_or_materialize_defaults(): void
    {
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $this->window(User::factory()->create());
        $this->assertSame(['windows' => [], 'meta' => ['timezone' => 'Europe/Kyiv', 'current_page' => 1,
            'per_page' => 20, 'total' => 0, 'last_page' => 1]], $this->data($owner));
        $this->assertDatabaseCount('study_availability_windows', 1);
        $this->assertDatabaseCount('planning_preferences', 0);
        $this->assertDatabaseCount('ai_pending_actions', 0);
    }

    public static function invalidArguments(): iterable
    {
        foreach (['day_of_week', 'page', 'per_page'] as $field) {
            foreach ([null, true, false, '1', 1.0, 0, -1, [], ['value' => 1]] as $index => $value) {
                yield $field.' malformed '.$index => [[$field => $value]];
            }
        }
        yield 'weekday upper bound' => [['day_of_week' => 8]];
        yield 'page size upper bound' => [['per_page' => 51]];
        foreach (['user_id', 'owner_id', 'timezone', 'window_id', 'class', 'method', 'unknown'] as $field) {
            yield 'unknown '.$field => [[$field => 123]];
        }
        yield 'positional argument' => [[1]];
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
        $this->assertSame(['ok' => false, 'tool' => self::TOOL,
            'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']], $result);
        $this->assertSame([], $queries);
    }

    public function test_offset_overflow_and_invalid_identity_have_safe_errors(): void
    {
        $owner = User::factory()->create();
        $this->assertSame(['ok' => false, 'tool' => self::TOOL,
            'error' => ['code' => 'invalid_arguments', 'message' => 'Invalid tool arguments.']],
            app(ToolExecutor::class)->execute($owner, self::TOOL, ['page' => PHP_INT_MAX, 'per_page' => 50]));
        $this->assertSame('access_denied', app(ToolExecutor::class)->execute(new User, self::TOOL, [])['error']['code']);
        $stale = clone $owner;
        $owner->delete();
        $this->assertSame('access_denied', app(ToolExecutor::class)->execute($owner, self::TOOL, [])['error']['code']);
        $this->assertSame('not_found', app(ToolExecutor::class)->execute($stale, self::TOOL, [])['error']['code']);
    }

    public static function providers(): array
    {
        return [['openai'], ['gemini']];
    }

    #[DataProvider('providers')]
    public function test_fake_provider_orchestration_returns_owned_windows_without_business_writes_or_pending_actions(string $provider): void
    {
        config(['ai.provider' => $provider, 'ai.openai_key' => 'fake-test-key', 'ai.gemini_key' => 'fake-test-key', 'ai.model' => 'gpt-5.4-mini']);
        $owner = User::factory()->create(['timezone' => 'Europe/Kyiv']);
        $window = $this->window($owner, ['ends_at' => '24:00:00']);
        $this->window(User::factory()->create());
        $conversation = AiConversation::factory()->for($owner)->create();
        $this->actingAs($owner, 'web');
        $before = $this->businessSnapshot();
        $expected = app(ToolExecutor::class)->execute($owner, self::TOOL, []);
        $arguments = $provider === 'openai' ? '{"day_of_week":null,"page":null,"per_page":null}' : '{}';
        Http::fake(['*' => Http::sequence()
            ->push($this->aiResponse([$this->aiCall(self::TOOL, $arguments)]))
            ->push($this->aiResponse([$this->aiText('Your configured weekly windows are listed.')]))]);
        $response = $this->postJson('/api/ai/conversations/'.$conversation->id.'/messages',
            ['content' => 'Read my configured weekly study availability.'], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.tools.0.name', self::TOOL)->assertJsonPath('data.tools.0.ok', true);
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseCount('ai_pending_actions', 0);
        $toolMessage = $conversation->messages()->where('role', 'tool')->sole();
        $this->assertSame($expected, json_decode($toolMessage->content, true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([$window->id], array_column($expected['data']['windows'], 'id'));
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use ($provider) {
            $definitions = $provider === 'openai' ? $request['tools'] : $request['tools'][0]['functionDeclarations'];
            $this->assertCount(46, $definitions);
            $this->assertContains(self::TOOL, array_column($definitions, 'name'));
            $instructions = $provider === 'openai' ? $request['instructions'] : $request['systemInstruction']['parts'][0]['text'];
            $this->assertStringContainsString('Configured weekly availability is different from calculated free study slots.', $instructions);
            $this->assertStringContainsString('find_free_study_slots calculates free time considering the existing schedule and study reservations.', $instructions);
            $this->assertStringContainsString('A successful availability read does not authorize modifying availability.', $instructions);
            $this->assertStringContainsString('Never claim you changed an availability window when you only read its data.', $instructions);

            return true;
        });
        $this->assertNull($response->json('data.tools.0.error_code'));
    }

    private function businessSnapshot(): array
    {
        $result = [];
        foreach (Schema::getTableListing(null, false) as $table) {
            if (in_array($table, ['ai_conversations', 'ai_conversation_turns', 'ai_messages'], true)) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode((array) $row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $result[$table] = $rows;
        }
        ksort($result);

        return $result;
    }
}
