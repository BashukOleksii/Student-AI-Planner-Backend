<?php

namespace Tests\Feature;

use App\Models\AiMessage;
use App\Models\AiPendingAction;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\InteractsWithPlanningActions;
use Tests\TestCase;

class AiSessionLifecycleActionsTest extends TestCase
{
    use DatabaseTruncation, InteractsWithPlanningActions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.provider' => 'openai', 'ai.openai_key' => 'fake', 'ai.model' => 'offline', 'ai.requests_per_minute' => 60]);
    }

    public static function actions(): array
    {
        return [['propose_session_mark_missed', 'missed'], ['propose_session_complete', 'completed']];
    }

    private function fixture(string $name): array
    {
        $f = $this->planningFixture('propose_session_reschedule');
        $f['source']->update(['starts_at' => now('UTC')->subHours(2), 'ends_at' => now('UTC')->subHour()]);
        $f['name'] = $name;
        $f['arguments'] = ['study_session_id' => $f['source']->id];

        return $f;
    }

    #[DataProvider('actions')]
    public function test_confirmation_is_owned_atomic_and_replayable(string $name, string $status): void
    {
        $f = $this->fixture($name);
        $before = $this->planningSnapshot();
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $this->assertNotNull($id);
        $this->assertSame($before, $this->planningSnapshot());
        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->create(), 'web');
        $this->confirmPlanning($f, $id)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($f['owner'], 'web');
        $history = AiMessage::count();
        AiMessage::creating(fn () => throw new RuntimeException('injected history failure'));
        try {
            $this->confirmPlanning($f, $id)->assertStatus(500);
        } finally {
            AiMessage::flushEventListeners();
        }
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertSame($history, AiMessage::count());
        $this->assertSame('pending', AiPendingAction::findOrFail($id)->status);
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
        $response = $this->confirmPlanning($f, $id)->assertOk()->json();
        $this->assertSame($status, $f['source']->fresh()->status->value);
        $this->assertSame('cancelled', $f['reminder']->fresh()->status->value);
        $after = $this->planningSnapshot();
        $response['replayed'] = true;
        $this->assertSame($response, $this->confirmPlanning($f, $id)->assertOk()->json());
        $this->assertSame($after, $this->planningSnapshot());
    }

    #[DataProvider('actions')]
    public function test_stale_review_cannot_mutate_session_or_issue_receipt(string $name, string $status): void
    {
        $f = $this->fixture($name);
        $id = $this->proposePlanning($f)->assertOk()->json('data.action.id');
        $this->assertNotNull($id);
        $f['task']->update(['estimated_minutes' => 300]);
        $before = $this->planningSnapshot();
        $this->confirmPlanning($f, $id)->assertConflict();
        $this->assertSame($before, $this->planningSnapshot());
        $this->assertSame('pending', AiPendingAction::findOrFail($id)->status);
        $this->assertNull(AiPendingAction::findOrFail($id)->receipt);
    }
}
