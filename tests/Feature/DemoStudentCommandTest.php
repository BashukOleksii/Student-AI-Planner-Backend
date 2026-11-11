<?php

namespace Tests\Feature;

use App\Models\AiPendingAction;
use App\Models\User;
use App\Services\Demo\DemoStudentService;
use App\Services\Planning\PlanningContextService;
use App\Services\Planning\RecoverySuggestionService;
use App\Services\Progress\ProgressStatisticsService;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\TestCase;

#[Group('mysql')]
#[Group('security')]
class DemoStudentCommandTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12T08:00:00Z'));
        // The parent guard already verified a disposable testing DB before any migrations.
        $this->app['env'] = 'local';
        config(['demo.enabled' => true, 'demo.database' => DB::getDatabaseName(),
            'demo.server_uuid' => DB::selectOne('SELECT @@server_uuid AS id')->id,
            'mail.default' => 'array', 'queue.default' => 'sync']);
        $this->beforeApplicationDestroyed(function (): void {
            $this->app['env'] = 'testing';
            config(['database.default' => 'mysql']);
            $this->truncateTablesForAllConnections();
        });
    }

    private function command(array $arguments = [], bool $confirm = true, bool $interactive = true, bool $humanEnvironment = true): array
    {
        $tester = new CommandTester(Artisan::all()['demo:seed-student']);
        $tester->setInputs([$confirm ? 'yes' : 'no']);
        $previous = ['CI' => getenv('CI'), 'GITHUB_ACTIONS' => getenv('GITHUB_ACTIONS')];
        try {
            if ($humanEnvironment) {
                putenv('CI=false');
                putenv('GITHUB_ACTIONS=false');
            }
            $status = $tester->execute($arguments, ['interactive' => $interactive]);
        } finally {
            foreach ($previous as $key => $value) {
                putenv($value === false ? $key : $key.'='.$value);
            }
        }

        // Successful credentials stay only in memory. Never include this buffer in assertion messages/artifacts.
        return [$status, $tester->getDisplay()];
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_nonlocal_environments_are_refused_without_writes_or_credentials(string $environment): void
    {
        $this->app['env'] = $environment;
        [$status, $output] = $this->command();
        $this->assertSame(1, $status);
        $this->assertFalse(str_contains($output, 'Password:'));
        $this->assertDatabaseCount('users', 0);
    }

    public static function unsafeEnvironments(): array
    {
        return [['production'], ['staging'], ['testing']];
    }

    #[DataProvider('unsafeSettings')]
    public function test_explicit_approval_identity_and_delivery_guards_are_enforced(string $field, mixed $value): void
    {
        config([$field => $value]);
        [$status, $output] = $this->command();
        $this->assertSame(1, $status);
        $this->assertFalse(str_contains($output, 'Password:'));
        $this->assertFalse(str_contains($output, 'DEMO STUDENT CREATED'));
        // Query the still-verified connection directly when the default selector is deliberately invalid.
        $this->assertSame(0, DB::connection('mysql')->table('users')->count());
    }

    public static function unsafeSettings(): array
    {
        return [['demo.enabled', false], ['demo.database', 'not_approved'], ['demo.server_uuid', 'wrong-server'],
            ['database.default', 'sqlite'], ['mail.default', 'smtp'], ['queue.default', 'database']];
    }

    public function test_ci_console_execution_is_refused_before_any_credentials_or_data(): void
    {
        $previous = getenv('CI');
        try {
            putenv('CI=true');
            [$status, $output] = $this->command(humanEnvironment: false);
            $this->assertSame(1, $status);
            $this->assertFalse(str_contains($output, 'Password:'));
            $this->assertDatabaseCount('users', 0);
        } finally {
            putenv($previous === false ? 'CI' : 'CI='.$previous);
        }
    }

    public function test_inspection_declining_and_noninteractive_invocation_never_seed(): void
    {
        config(['demo.enabled' => false]);
        [$status, $output] = $this->command(['--inspect' => true]);
        $this->assertSame(0, $status);
        $this->assertFalse(str_contains($output, 'Password:'));
        config(['demo.enabled' => true]);
        foreach ([[false, true], [true, false]] as [$confirm, $interactive]) {
            [$status, $output] = $this->command(confirm: $confirm, interactive: $interactive);
            $this->assertSame(1, $status);
            $this->assertFalse(str_contains($output, 'Password:'));
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_successful_command_prints_credentials_once_only_after_valid_atomic_creation(): void
    {
        [$status, $output] = $this->command();
        $this->assertSame(0, $status); // Do not print the output on a failed assertion.
        $this->assertSame(1, preg_match('/^Email: (\S+@example\.test)$/m', $output, $emails));
        $this->assertSame(1, preg_match('/^Password: ([a-zA-Z0-9]{32})$/m', $output, $passwords));
        $this->assertSame(1, substr_count($output, 'Password:'));
        $owner = User::where('email', $emails[1])->sole();
        $this->assertTrue(Hash::check($passwords[1], $owner->password));
        $this->assertTrue(Auth::guard('web')->attempt(['email' => $emails[1], 'password' => $passwords[1]]));
        $this->assertSame($owner->id, Auth::guard('web')->id());
        $this->assertFalse(array_key_exists('password', $owner->toArray()));
        unset($output, $passwords);
        $this->assertDataset($owner);
    }

    #[DataProvider('demoDates')]
    public function test_service_is_repeatable_owned_and_uses_valid_relative_dates(string $date): void
    {
        $this->travelTo(CarbonImmutable::parse($date));
        $existing = User::factory()->create(['name' => 'Unrelated student'])->fresh();
        $existing->tasks()->create(['title' => 'Keep untouched', 'priority' => 2, 'status' => 'pending']);
        $keepTask = $existing->tasks()->sole();
        $existing->reminders()->create(['task_id' => $keepTask->id, 'trigger_at' => now('UTC')->subDay(), 'status' => 'scheduled', 'message' => 'Unrelated due reminder']);
        $before = [$existing->getAttributes(), $existing->tasks()->get()->toJson(), $existing->reminders()->get()->toJson()];
        $first = app(DemoStudentService::class)->create(Str::random(32));
        $second = app(DemoStudentService::class)->create(Str::random(32));
        $this->assertNotSame($first['user']->id, $second['user']->id);
        $this->assertTrue($first['user']->email !== $second['user']->email);
        $this->assertSame($before, [$existing->fresh()->getAttributes(), $existing->tasks()->get()->toJson(), $existing->reminders()->get()->toJson()]);
        $this->assertDatabaseCount('users', 3);
        $this->assertDataset($first['user']);
        $this->assertDataset($second['user']);
    }

    public static function demoDates(): array
    {
        return [['2026-11-12T08:00:00Z'], ['2026-11-15T22:55:00Z'], ['2026-03-27T21:30:00Z'], ['2026-10-23T21:30:00Z']];
    }

    private function assertDataset(User $owner): void
    {
        $this->assertSame('Europe/Kyiv', $owner->timezone);
        $this->assertSame(8, $owner->subjects()->count());
        $this->assertSame(8, $owner->teachers()->count());
        foreach ($owner->subjects()->with('teachers')->get() as $subject) {
            $this->assertSame($owner->id, $subject->teachers->sole()->user_id);
            $this->assertSame($owner->id, $subject->teachers->sole()->pivot->user_id);
        }
        $schedule = $owner->semesterSchedules()->sole();
        $this->assertSame(15, $schedule->weeklyLessonRules()->count());
        $this->assertSame(3, $schedule->dateExceptions()->count());
        $this->assertSame(1, $schedule->dateExceptions()->where('kind', 'working_saturday')->count());
        $this->assertSame(24, $owner->tasks()->count());
        $this->assertSame(6, $owner->tasks()->where('status', 'completed')->count());
        foreach ($owner->tasks()->where('status', 'completed')->get() as $task) {
            $this->assertTrue($task->completed_at >= $task->created_at);
        }
        $this->assertSame(2, $owner->tasks()->where('status', 'missed')->count());
        $this->assertSame(15, DB::table('subtasks')->whereIn('task_id', $owner->tasks()->select('id'))->count());
        $this->assertSame(5, DB::table('subtasks')->whereIn('task_id', $owner->tasks()->select('id'))->where('status', 'completed')->count());
        $sessions = $owner->studySessions()->get();
        app(PlanningContextService::class)->validateSessionReferences($owner, $sessions);
        $this->assertSame(2, $sessions->where('status.value', 'missed')->count());
        $this->assertSame(1, $sessions->where('status.value', 'rescheduled')->count());
        $this->assertGreaterThan(0, $sessions->filter(fn ($s) => $s->status->value === 'completed' && $s->actual_minutes === null)->count());
        $this->assertSame(0, $sessions->filter(fn ($s) => $s->status->value === 'planned' && $s->starts_at <= now())->count());
        $previousEnd = null;
        foreach ($sessions->filter(fn ($s) => in_array($s->status->value, ['planned', 'completed'], true))->sortBy('starts_at') as $session) {
            $this->assertTrue($previousEnd === null || $session->starts_at >= $previousEnd, 'Study sessions must not overlap.');
            $previousEnd = $session->ends_at;
        }
        $this->assertSame(8, $owner->reminders()->count());
        $this->assertSame(2, $owner->reminders()->where('status', 'sent')->count());
        $this->assertSame(1, $owner->reminders()->where('status', 'cancelled')->count());
        $this->assertSame(2, $owner->notifications()->count());
        $sent = $owner->reminders()->where('status', 'sent')->firstOrFail();
        $this->assertSame('skipped', app(ReminderDeliveryService::class)->process($owner->id, $sent->id)['status']);
        $this->assertSame(2, $owner->notifications()->count());
        foreach ($owner->notifications as $notification) {
            $this->assertSame('sent', $owner->reminders()->findOrFail($notification->reminder_id)->status->value);
        }
        $review = $owner->recoverySuggestions()->where('status', 'pending')->sole();
        $this->assertSame('replan', $review->kind);
        $this->assertNull($review->receipt);
        $this->assertTrue($review->review['actionable']);
        $this->assertSame('unchanged', app(RecoverySuggestionService::class)->detect($owner->id));
        $this->assertSame(2, $owner->aiConversations()->count());
        $this->assertSame(0, AiPendingAction::whereIn('conversation_id', $owner->aiConversations()->select('id'))->count());
        $today = CarbonImmutable::now('Europe/Kyiv')->startOfDay();
        $data = app(ProgressStatisticsService::class)->calculate($owner, $today->subDays(14)->toDateString(), $today->addDays(14)->toDateString());
        $this->assertSame(6, $data['tasks']['current']['completed']);
        $known = $sessions->filter(fn ($s) => $s->status->value === 'completed' && $s->actual_minutes !== null)->sum('actual_minutes');
        $unknown = $sessions->filter(fn ($s) => $s->status->value === 'completed' && $s->actual_minutes === null)->count();
        $this->assertSame($known, $data['study_sessions']['started_in_period']['completed_actual_minutes']);
        $this->assertSame($unknown, $data['study_sessions']['started_in_period']['completed_without_actual_minutes']);
    }

    public function test_failure_after_delivery_and_real_recovery_rolls_back_all_records_and_never_prints_credentials(): void
    {
        $service = $this->partialMock(RecoverySuggestionService::class);
        $service->shouldReceive('detectWithinTransaction')->once()->andReturnUsing(function ($ownerId, $days = 7) {
            // Delegate to the actual core before simulating a late failure.
            $real = app()->build(RecoverySuggestionService::class);
            $real->detectWithinTransaction($ownerId, $days);
            throw new \RuntimeException('private SQL and credential details');
        });
        [$status, $output] = $this->command();
        $this->assertSame(1, $status);
        $this->assertFalse(str_contains($output, 'Password:'));
        $this->assertFalse(str_contains($output, 'private SQL'));
        foreach (['users', 'tasks', 'subtasks', 'study_sessions', 'reminders', 'notifications', 'recovery_suggestions', 'semester_schedules'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_new_internal_operations_require_a_transaction_and_public_guards_remain_intact(): void
    {
        foreach ([fn () => app(RecoverySuggestionService::class)->detectWithinTransaction(1),
            fn () => app(ReminderDeliveryService::class)->processWithinTransaction(1, 1)] as $call) {
            try {
                $call();
                $this->fail('A standalone internal operation must be refused.');
            } catch (\LogicException) {
                $this->assertSame(0, DB::transactionLevel());
            }
        }
    }
}
