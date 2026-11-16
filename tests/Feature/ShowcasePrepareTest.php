<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\ScheduleImportBatch;
use App\Models\ScheduleImportRow;
use App\Models\Task;
use App\Models\User;
use App\Services\Imports\ScheduleWorkbookParser;
use App\Services\Planning\PlanningConstraintService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\RecoveryCheckTriggerService;
use App\Services\Planning\StudyPlanningService;
use App\Services\Reminders\ReminderMutationService;
use App\Services\Schedule\EffectiveScheduleService;
use App\Services\Showcase\ShowcaseDatabase;
use App\Services\Showcase\ShowcasePreparationService as Preparation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

#[Group('mysql')]
#[Group('workflow')]
class ShowcasePrepareTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Forward-only migrations on the disposable server; no migrate:fresh or FK bypass.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        RefreshDatabaseState::$migrated = true;
        config(['session.driver' => 'database', 'session.connection' => null]);
        $this->travelTo(CarbonImmutable::parse('2026-11-16 10:00:00', 'Europe/Kyiv'));
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            unlink($path);
        }
        app(RecoveryCheckTriggerService::class)->withoutRequests(function (): void {
            foreach (User::all() as $user) {
                DB::transaction(function () use ($user): void {
                    app(ShowcaseDatabase::class)->delete(User::whereKey($user->id)->lockForUpdate()->firstOrFail());
                });
            }
        });
        $this->travelBack();
        parent::tearDown();
    }

    private function file(): string
    {
        return getenv('SHOWCASE_TEST_XLSX') ?: __DIR__.'/../Fixtures/ScheduleImports/Приклад розкладу.xlsx';
    }

    private function arguments(array $overrides = []): array
    {
        return array_replace(['file' => $this->file(), '--starts-on' => '2026-09-01', '--ends-on' => '2026-12-20'], $overrides);
    }

    private function runPreparation(string $password = 'Showcase-test-password'): void
    {
        $this->artisan('showcase:prepare', $this->arguments())
            ->expectsQuestion('Для підтвердження введіть повну email-адресу', Preparation::EMAIL)
            ->expectsQuestion('Новий пароль', $password)
            ->expectsQuestion('Повторіть новий пароль', $password)
            ->expectsOutputToContain('Готово: '.Preparation::EMAIL)
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();
    }

    public function test_creation_preserves_workbook_and_allocates_all_open_work_around_lessons_and_existing_sessions_and_logs_in(): void
    {
        $this->runPreparation();
        $owner = User::where('email', Preparation::EMAIL)->sole();
        $this->assertSame('Oleksii', $owner->name);
        $this->assertSame('Europe/Kyiv', $owner->timezone);
        $this->assertTrue(Hash::check('Showcase-test-password', $owner->password));
        $this->assertFalse($owner->reminder_email_enabled);
        $this->assertSame('ХПФК', $owner->educationInstitutions()->sole()->name);
        $this->assertSame('1 семестр', $owner->academicPeriods()->sole()->name);
        $this->assertSame(10, $owner->tasks()->count());
        $this->assertSame(3, $owner->studySessions()->where('status', 'completed')->count());
        $this->assertSame(2, $owner->studySessions()->where('status', 'planned')->count());
        $this->assertSame(5, $owner->tasks()->where('status', 'pending')->whereDoesntHave('studySessions')->count());
        $this->assertSame(1, $owner->reminders()->count());
        $this->assertDatabaseCount('recovery_suggestions', 0);
        $this->assertDatabaseCount('recovery_scan_states', 0);
        $this->assertDatabaseCount('reminder_email_intents', 0);
        $template = app(ScheduleWorkbookParser::class)->parse($this->file(), semester: true);
        $schedule = $owner->semesterSchedules()->sole();
        $rules = $schedule->weeklyLessonRules()->with(['subject', 'teacher'])->get();
        $this->assertCount(count($template['rules']), $rules);
        foreach ($template['rules'] as $raw) {
            $rule = $rules->where('weekday', $raw['weekday'])->where('pair_label', $raw['pair_label'])->where('week_pattern', $raw['parity'])->sole();
            $this->assertSame($raw['subject_name'], $rule->subject->name);
            $this->assertSame($raw['teacher_name'], $rule->teacher->name);
            $this->assertSame($raw['starts_at_local'], $rule->starts_at_local);
            $this->assertSame($raw['ends_at_local'], $rule->ends_at_local);
            $this->assertSame($raw['room'], $rule->room);
        }
        $subjects = [];
        foreach (['2026-11-17', '2026-11-24'] as $date) {
            $fourth = collect(app(EffectiveScheduleService::class)->date($owner, $schedule, $date))->where('pair_number', 4);
            $this->assertCount(1, $fourth);
            $subjects[] = $fourth->sole()['subject_id'];
        }
        $this->assertCount(2, array_unique($subjects));
        $plan = app(StudyPlanningService::class)->plan($owner, '2026-11-16', '2026-12-16');
        $this->assertTrue($plan['summary']['fully_allocated']);
        $this->assertSame([], $plan['warnings']);
        $this->assertNotEmpty($plan['sessions']);
        $context = app(PlanningContextService::class)->load($owner, '2026-11-16', '2026-12-16');
        $this->assertNotEmpty($context['break_anchors']);
        foreach ($plan['sessions'] as $i => $session) {
            $others = array_map(fn ($other) => ['start' => $other['start'], 'end' => $other['end']], array_values(array_filter($plan['sessions'], fn ($other, $j) => $i !== $j, ARRAY_FILTER_USE_BOTH)));
            $deadline = collect($plan['work_results'])->first(fn ($work) => $work['task_id'] === $session['task_id'] && $work['subtask_id'] === $session['subtask_id'])['effective_deadline_utc'];
            $this->assertSame([], app(PlanningConstraintService::class)->evaluate(['start' => $session['start'], 'end' => $session['end']], $context['free_intervals'],
                [...$context['break_anchors'], ...$others], [...$context['workload_sessions'], ...$others], 'Europe/Kyiv', $context['preferences'], $deadline));
        }
        $this->withHeader('Origin', 'http://localhost:5173')->withCredentials();
        config(['sanctum.stateful' => ['localhost:5173']]);
        Auth::forgetGuards();
        $this->postJson('/api/auth/login', ['email' => Preparation::EMAIL, 'password' => 'Showcase-test-password'])->assertOk();
        $this->assertAuthenticatedAs($owner, 'web');
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public function test_repeat_replaces_the_account_cleans_all_dependencies_and_leaves_another_account_byte_identical(): void
    {
        $this->runPreparation();
        $old = User::where('email', Preparation::EMAIL)->sole();
        $this->enrich($old);
        $other = User::factory()->create(['email' => 'other-showcase@example.test']);
        $this->enrich($other, false);
        $otherBefore = $this->ownerSnapshot($other);
        $this->runPreparation('Replacement-test-password');
        $this->assertDatabaseMissing('users', ['id' => $old->id]);
        $new = User::where('email', Preparation::EMAIL)->sole();
        $this->assertNotSame($old->id, $new->id);
        $this->assertTrue(Hash::check('Replacement-test-password', $new->password));
        $this->assertSame($otherBefore, $this->ownerSnapshot($other));
        foreach ($this->ownerTables() as $table) {
            $this->assertSame(0, DB::table($table)->where('user_id', $old->id)->count(), $table);
        }
        $this->assertSame(0, $old->notifications()->count());
        $this->assertSame(0, PersonalAccessToken::where('tokenable_id', $old->id)->where('tokenable_type', $old->getMorphClass())->count());
        $this->assertFalse(Password::broker()->tokenExists($new, 'irrelevant-token'));
        $this->assertDatabaseMissing(config('auth.passwords.users.table'), ['email' => $old->email]);
        $this->assertDatabaseCount('ai_messages', 1); // the other owner's conversation
        $this->assertDatabaseCount('ai_conversation_turns', 1);
        $this->assertDatabaseCount('ai_pending_actions', 1);
    }

    public function test_wrong_confirmation_and_dry_run_do_not_write_any_records(): void
    {
        $this->runPreparation();
        $before = $this->snapshot();
        $this->artisan('showcase:prepare', $this->arguments())->expectsQuestion('Для підтвердження введіть повну email-адресу', 'wrong@example.test')
            ->expectsOutputToContain('Підтвердження неправильне')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('showcase:prepare', $this->arguments(['--dry-run' => true]))->expectsOutputToContain('DRY RUN')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_password_confirmation_and_validation_refuse_before_writing(): void
    {
        $before = $this->snapshot();
        $this->artisan('showcase:prepare', $this->arguments(['--dry-run' => true, '--no-interaction' => true]))->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        foreach ([['valid-test-password', 'different-password'], ['short', 'short']] as [$password, $repeat]) {
            $this->artisan('showcase:prepare', $this->arguments())
                ->expectsQuestion('Для підтвердження введіть повну email-адресу', Preparation::EMAIL)
                ->expectsQuestion('Новий пароль', $password)->expectsQuestion('Повторіть новий пароль', $repeat)->assertFailed();
            $this->assertSame($before, $this->snapshot());
        }
    }

    public function test_invalid_workbook_is_rejected_before_confirmation_or_deletion_with_a_cell_error(): void
    {
        $this->runPreparation();
        $before = $this->snapshot();
        $path = $this->changedWorkbook(fn ($sheet) => $sheet->setCellValue('D30', '=1+1'));
        $this->artisan('showcase:prepare', $this->arguments(['file' => $path]))
            ->expectsOutputToContain('клітинка D30')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failure_after_the_old_account_is_deleted_restores_every_old_record_and_credentials(): void
    {
        $this->runPreparation();
        $owner = User::where('email', Preparation::EMAIL)->sole();
        $this->enrich($owner);
        $before = $this->snapshot();
        $trigger = app(RecoveryCheckTriggerService::class);
        // A real late persistence failure, after deletion, import and history.
        $this->mock(ReminderMutationService::class)->shouldReceive('create')->once()
            ->andReturnUsing(function () use ($owner): never {
                $this->assertDatabaseMissing('users', ['id' => $owner->id]);
                $this->assertDatabaseHas('semester_schedule_imports', ['status' => 'committed']);
                throw new RuntimeException('Injected late failure');
            });
        $this->artisan('showcase:prepare', $this->arguments())
            ->expectsQuestion('Для підтвердження введіть повну email-адресу', Preparation::EMAIL)
            ->expectsQuestion('Новий пароль', 'Unused-new-password')->expectsQuestion('Повторіть новий пароль', 'Unused-new-password')
            ->expectsOutputToContain('Injected late failure')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertTrue(Hash::check('Showcase-test-password', $owner->fresh()->password));
        $this->assertSame($trigger, app(RecoveryCheckTriggerService::class));
    }

    public function test_too_short_period_rolls_back_and_noninteractive_write_is_refused(): void
    {
        $this->runPreparation();
        $before = $this->snapshot();
        $this->artisan('showcase:prepare', $this->arguments(['--ends-on' => '2026-11-16']))
            ->expectsQuestion('Для підтвердження введіть повну email-адресу', Preparation::EMAIL)
            ->expectsQuestion('Новий пароль', 'Unused-new-password')->expectsQuestion('Повторіть новий пароль', 'Unused-new-password')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->artisan('showcase:prepare', $this->arguments(['--no-interaction' => true]))->assertFailed();
        $this->artisan('showcase:prepare', $this->arguments(['--quiet' => true]))->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_import_review_rejects_semantic_errors_without_writes_and_unsafe_sessions_fail_closed(): void
    {
        $before = $this->snapshot();
        $path = $this->changedWorkbook(fn ($sheet) => $sheet->setCellValue('D31', null));
        $this->artisan('showcase:prepare', $this->arguments(['file' => $path, '--dry-run' => true]))->assertFailed();
        $this->assertSame($before, $this->snapshot());
        config(['session.driver' => 'file']);
        $this->artisan('showcase:prepare', $this->arguments(['--dry-run' => true]))
            ->expectsOutputToContain('database-сесії')->assertFailed();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_cross_owner_references_refuse_replacement_without_mutating_either_account(): void
    {
        $this->runPreparation();
        $owner = User::where('email', Preparation::EMAIL)->sole();
        $other = User::factory()->create(['email' => 'cross-owner@example.test']);
        $task = app(RecoveryCheckTriggerService::class)->withoutRequests(fn () => Task::factory()->for($other)
            ->create(['subject_id' => $owner->subjects()->firstOrFail()->id]));
        $before = $this->snapshot();
        try {
            $this->artisan('showcase:prepare', $this->arguments())
                ->expectsQuestion('Для підтвердження введіть повну email-адресу', Preparation::EMAIL)
                ->expectsQuestion('Новий пароль', 'Unused-new-password')->expectsQuestion('Повторіть новий пароль', 'Unused-new-password')
                ->expectsOutputToContain('записи іншого власника')->assertFailed();
            $this->assertSame($before, $this->snapshot());
        } finally {
            DB::table('tasks')->where('id', $task->id)->update(['subject_id' => null]);
        }
    }

    public function test_competing_email_lock_refuses_a_second_preparation_and_is_not_released_by_it(): void
    {
        $before = $this->snapshot();
        $name = 'showcase:'.substr(hash('sha256', DB::connection()->getDatabaseName().':'.Preparation::EMAIL), 0, 48);
        config(['database.connections.showcase_competitor' => config('database.connections.'.config('database.default'))]);
        $competitor = DB::connection('showcase_competitor');
        $this->assertSame(1, $competitor->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired);
        try {
            $this->artisan('showcase:prepare', $this->arguments())
                ->expectsQuestion('Для підтвердження введіть повну email-адресу', Preparation::EMAIL)
                ->expectsQuestion('Новий пароль', 'Unused-new-password')->expectsQuestion('Повторіть новий пароль', 'Unused-new-password')
                ->expectsOutputToContain('Інший запуск уже готує')->assertFailed();
            $this->assertSame($before, $this->snapshot());
            $this->assertNotNull($competitor->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$name])->owner);
        } finally {
            $competitor->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
            DB::purge('showcase_competitor');
        }
    }

    private function changedWorkbook(callable $change): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'showcase-test-');
        $path = $temporary.'.xlsx';
        rename($temporary, $path);
        $this->temporaryFiles[] = $path;
        $book = IOFactory::load($this->file());
        $change($book->getSheetByName('Sheet1'));
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private function ownerTables(): array
    {
        return array_column(DB::select("SELECT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'user_id'"), 'name');
    }

    private function snapshot(): array
    {
        $result = [];
        foreach (DB::select("SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'") as $table) {
            $rows = DB::table($table->name)->get()->map(fn ($row) => json_encode($row))->all();
            sort($rows);
            $result[$table->name] = $rows;
        }

        return $result;
    }

    private function ownerSnapshot(User $owner): array
    {
        $result = ['user' => $owner->fresh()->getAttributes(), 'notifications' => $owner->notifications()->get()->toJson(),
            'tokens' => PersonalAccessToken::where('tokenable_type', $owner->getMorphClass())->where('tokenable_id', $owner->id)->get()->toJson(),
            'resets' => DB::table(config('auth.passwords.users.table'))->where('email', $owner->email)->get()->toJson()];
        foreach ($this->ownerTables() as $table) {
            $result[$table] = DB::table($table)->where('user_id', $owner->id)->get()->toJson();
        }

        return $result;
    }

    private function enrich(User $owner, bool $schedule = true): void
    {
        app(RecoveryCheckTriggerService::class)->withoutRequests(function () use ($owner, $schedule): void {
            DB::transaction(function () use ($owner, $schedule): void {
                $task = Task::factory()->for($owner)->create();
                $task->subtasks()->create(['title' => 'Archived child', 'position' => 1, 'estimated_minutes' => 30]);
                $task->delete();
                $conversation = $owner->aiConversations()->create(['title' => 'Old conversation']);
                $conversation->messages()->create(['role' => 'user', 'content' => 'Previous message']);
                $turn = DB::table('ai_conversation_turns')->insertGetId(['conversation_id' => $conversation->id, 'request_id' => Str::uuid(), 'input_hash' => str_repeat('a', 64)]);
                DB::table('ai_pending_actions')->insert(['id' => Str::uuid(), 'conversation_id' => $conversation->id, 'turn_id' => $turn,
                    'call_id' => 'old-call', 'type' => 'propose_task_create', 'arguments' => '{}', 'preview' => '{}', 'expires_at' => '2026-11-17 12:00:00']);
                PersonalAccessToken::forceCreate(['tokenable_type' => $owner->getMorphClass(), 'tokenable_id' => $owner->id, 'name' => 'old-token', 'token' => hash('sha256', Str::random(40)), 'abilities' => ['*']]);
                Password::broker()->createToken($owner);
                DB::table(config('session.table'))->insert(['id' => 'session-'.$owner->id, 'user_id' => $owner->id, 'payload' => base64_encode('old-session'), 'last_activity' => time()]);
                $notification = $owner->notifications()->create(['id' => Str::uuid(), 'type' => 'study_reminder', 'data' => ['message' => 'Old reminder']]);
                DB::table('reminder_email_intents')->insert(['user_id' => $owner->id, 'notification_id' => $notification->id, 'recipient_fingerprint' => str_repeat('a', 64), 'next_attempt_at' => '2026-11-17 12:00:00']);
                DB::table('recovery_scan_states')->insertOrIgnore(['user_id' => $owner->id]);
                if ($schedule) {
                    $semester = $owner->semesterSchedules()->sole();
                    $session = $owner->studySessions()->where('status', 'planned')->firstOrFail();
                    DB::table('automatic_session_reminders')->insert(['user_id' => $owner->id, 'study_session_id' => $session->id,
                        'channel' => 'in_app', 'lead_minutes' => 10, 'session_starts_at' => $session->getRawOriginal('starts_at'),
                        'trigger_at' => $session->starts_at->subMinutes(10)->format('Y-m-d H:i:s'), 'status' => 'scheduled']);
                    DB::table('recovery_suggestions')->insert(['id' => Str::uuid(), 'user_id' => $owner->id, 'active_owner_id' => $owner->id,
                        'dedupe_key' => hash('sha256', (string) $owner->id), 'source_hash' => str_repeat('a', 64), 'need_hash' => str_repeat('b', 64),
                        'kind' => 'create_plan', 'status' => 'pending', 'start_date' => '2026-11-16', 'end_date' => '2026-11-30',
                        'review' => '{}', 'expires_at' => '2026-11-17 12:00:00']);
                    $rule = $semester->weeklyLessonRules()->firstOrFail();
                    $exception = $semester->dateExceptions()->create(['user_id' => $owner->id, 'occurs_on' => '2026-11-18', 'kind' => 'replacement']);
                    $exception->lessons()->create([...$rule->only(['user_id', 'semester_schedule_id', 'pair_number', 'pair_label', 'starts_at_local', 'ends_at_local', 'subject_id', 'teacher_id', 'room']), 'source_weekly_rule_id' => $rule->id]);
                    $semester->suppressedSlots()->create(['user_id' => $owner->id, 'weekday' => 3, 'pair_number' => 4, 'week_pattern' => 'all']);
                    $batch = ScheduleImportBatch::factory()->create(['user_id' => $owner->id, 'academic_period_id' => $semester->academic_period_id]);
                    ScheduleImportRow::factory()->create(['schedule_import_batch_id' => $batch->id]);
                    Lesson::factory()->create(['user_id' => $owner->id, 'academic_period_id' => $semester->academic_period_id, 'subject_id' => $rule->subject_id, 'schedule_import_batch_id' => $batch->id])->delete();
                }
            });
        });
    }
}
