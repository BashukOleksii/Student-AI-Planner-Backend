<?php

namespace Tests\Live;

use App\Exceptions\AiAgentException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithSpaBrowser;
use Tests\TestCase;

/** Deliberately outside both default PHPUnit directories. Explicit paid opt-in only. */
class OpenAiLiveSmokeTest extends TestCase
{
    use InteractsWithSpaBrowser;

    public function createApplication()
    {
        // Check before Laravel bootstrap, database connection, fixtures or provider work.
        if (getenv('AI_LIVE_SMOKE') !== '1' || getenv('AI_LIVE_ALLOW_PAID') !== '1') {
            throw new \RuntimeException('Live smoke NOT RUN: AI_LIVE_SMOKE=1 and AI_LIVE_ALLOW_PAID=1 are required.');
        }

        return parent::createApplication(); // Existing MySQL/testing/database-name safety guard.
    }

    public function test_bounded_disposable_live_observations_never_confirm_business_changes(): void
    {
        $this->assertTrue(config('ai.provider') === 'openai', 'The OpenAI provider must be configured.');
        $this->assertTrue(is_string(config('ai.openai_key')) && trim(config('ai.openai_key')) !== '', 'A securely configured API key is required.');
        $this->assertTrue(is_string(config('ai.model')) && config('ai.model') !== '', 'A compatible model is required.');
        foreach (['users', 'sessions', 'tasks', 'ai_conversations', 'ai_conversation_turns', 'ai_pending_actions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), 'Apply migrations to the disposable test database first.');
        }
        config(['ai.max_tool_rounds' => 3, 'ai.max_tool_calls' => 3, 'ai.max_output_tokens' => 384,
            'ai.request_timeout' => 15, 'ai.max_turn_seconds' => 60, 'ai.turn_lease_seconds' => 90, 'ai.requests_per_minute' => 10]);
        $requests = 0;
        Http::globalRequestMiddleware(function ($request) use (&$requests) {
            if ((string) $request->getUri() !== 'https://api.openai.com/v1/responses' || ++$requests > 12) {
                throw new AiAgentException('execution_limit_reached');
            }

            return $request;
        });
        $student = $this->registerBrowser();
        $id = $student['id'];
        try {
            foreach (['Live smoke algebra', 'Live smoke history'] as $title) {
                $this->browser('POST', '/api/tasks', ['title' => $title, 'estimated_minutes' => 30])->assertCreated();
            }
            $scenarios = [
                'greeting' => 'Hello. Briefly explain how you can help me.',
                'read' => 'Show my pending tasks.',
                'sequential' => 'List my tasks, then retrieve the subtasks for Live smoke algebra using its ID from that task result.',
                'clarification' => 'Move one of my study sessions next week. I have not chosen a session or a clock time.',
                'proposal' => 'Propose creating a task titled Live smoke proposed with an estimate of 30 minutes. Never confirm it.',
                'unavailable' => 'Find the subtasks for the task named No such smoke task ZZZ. Do not guess its identity.',
            ];
            foreach ($scenarios as $scenario => $content) {
                $conversation = $this->browser('POST', '/api/ai/conversations')->assertCreated()->json('data.id');
                $response = $this->browser('POST', '/api/ai/conversations/'.$conversation.'/messages', ['content' => $content],
                    ['Idempotency-Key' => (string) Str::uuid()]);
                $data = $response->json('data') ?? [];
                $unchanged = DB::table('tasks')->where('user_id', $id)->count() === 2
                    && DB::table('study_sessions')->where('user_id', $id)->count() === 0
                    && DB::table('reminders')->where('user_id', $id)->count() === 0;
                // No raw prompts, answers, IDs, tokens, credentials or continuation in operator output.
                fwrite(STDOUT, json_encode(['scenario' => $scenario, 'http_status' => $response->status(),
                    'tools' => array_column($data['tools'] ?? [], 'name'), 'pending_proposal' => isset($data['action']),
                    'business_unchanged' => $unchanged, 'provider_requests_total' => $requests], JSON_THROW_ON_ERROR)."\n");
                $response->assertOk();
                $this->assertTrue($unchanged);
                if ($scenario === 'proposal') {
                    $this->assertSame('confirmation_required', $data['action']['state'] ?? null);
                } elseif (in_array($scenario, ['greeting', 'clarification', 'unavailable'], true)) {
                    $this->assertArrayNotHasKey('action', $data);
                }
                $this->assertStringNotContainsString('preview_token', $response->getContent());
            }
        } finally {
            // Delete only this newly registered disposable student, never truncate a database.
            User::whereKey($id)->delete();
            DB::table('sessions')->where('user_id', $id)->delete();
        }
    }
}
