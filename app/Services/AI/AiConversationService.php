<?php

namespace App\Services\AI;

use App\Enums\AiMessageRole;
use App\Exceptions\AiAgentException;
use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class AiConversationService
{
    public function __construct(private AgentOrchestrator $orchestrator, private AgentLimits $limits) {}

    public function create(User $owner): AiConversation
    {
        $conversation = $owner->aiConversations()->create();
        if (! $conversation->exists || $conversation->getKey() === null) {
            throw new AiAgentException('persistence_error');
        }

        return $conversation;
    }

    public function find(User $owner, string $id): AiConversation
    {
        return $owner->aiConversations()->whereKey($id)->firstOrFail();
    }

    public function listing(User $owner, array $filters): LengthAwarePaginator
    {
        return $owner->aiConversations()->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20, ['id', 'created_at', 'updated_at'], 'page', $filters['page'] ?? 1);
    }

    public function messages(User $owner, string $id, array $filters): LengthAwarePaginator
    {
        $conversation = $this->find($owner, $id);
        $query = $conversation->messages()->whereIn('role', [AiMessageRole::User, AiMessageRole::Assistant, AiMessageRole::Tool]);
        // Cursor boundary keeps subsequent pages stable as new completed turns are appended.
        $through = $filters['through_id'] ?? (clone $query)->max('id') ?? 0;
        $page = $query->where('id', '<=', $through)->orderBy('id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $page->appends(['through_id' => $through, 'per_page' => $page->perPage()]);

        return $page;
    }

    public function send(User $owner, string $id, string $content, string $requestId): array
    {
        // A caller-owned transaction would retain locks throughout provider I/O and invalidate durability.
        if (DB::transactionLevel() !== 0) {
            throw new AiAgentException('persistence_error');
        }
        $claim = $this->claim($owner, $id, $content, $requestId);
        if ($claim['result'] !== null) {
            return $claim['result'];
        }
        try {
            $owner = $owner->newQuery()->whereKey($owner->getKey())->firstOrFail(['id', 'timezone']);
            $history = $this->history($owner, $id);
            $turn = $this->orchestrator->run($owner, $history, $content, fn () => $this->assertCurrent($claim));

            return $this->finish($owner, $id, $claim, $turn);
        } finally {
            // Conditional token match cannot release a successor worker's claim or a committed replay.
            try {
                DB::table('ai_conversation_turns')->where('id', $claim['id'])->where('lease_token', $claim['token'])
                    ->whereNull('result')->update(['lease_token' => null, 'lease_expires_at' => null]);
            } catch (Throwable) {
                // A database outage leaves an expiring lease, never a permanent conversation lock.
            }
        }
    }

    private function claim(User $owner, string $id, string $content, string $requestId): array
    {
        $lease = $this->limits->get('turn_lease_seconds');
        if ($lease <= $this->limits->get('max_turn_seconds')) {
            throw new AiAgentException('ai_unavailable');
        }

        return DB::transaction(function () use ($owner, $id, $content, $requestId, $lease): array {
            $owner->aiConversations()->whereKey($id)->lockForUpdate()->firstOrFail();
            $query = DB::table('ai_conversation_turns')->where('conversation_id', $id);
            $existing = (clone $query)->where('request_id', $requestId)->first();
            $hash = hash('sha256', $content);
            if ($existing !== null && ! hash_equals($existing->input_hash, $hash)) {
                throw new AiAgentException('idempotency_conflict');
            }
            if ($existing?->result !== null) {
                $stored = json_decode($existing->result, true, 64, JSON_THROW_ON_ERROR);

                // MySQL JSON normalizes object key order; replay the same public field order.
                return ['result' => ['conversation_id' => $stored['conversation_id'], 'user_message_id' => $stored['user_message_id'],
                    'assistant_message_id' => $stored['assistant_message_id'], 'content' => $stored['content'],
                    'tools' => array_map(fn ($tool) => ['call_id' => $tool['call_id'], 'name' => $tool['name'], 'ok' => $tool['ok'],
                        'error_code' => $tool['error_code']], $stored['tools'])]];
            }
            if ((clone $query)->whereNull('result')->where('lease_expires_at', '>', DB::raw('UTC_TIMESTAMP(6)'))->exists()) {
                throw new AiAgentException('conversation_busy');
            }
            $token = (string) Str::uuid();
            $values = ['lease_token' => $token, 'lease_expires_at' => DB::raw('DATE_ADD(UTC_TIMESTAMP(6), INTERVAL '.$lease.' SECOND)'),
                'updated_at' => DB::raw('UTC_TIMESTAMP()')];
            if ($existing === null) {
                $turnId = DB::table('ai_conversation_turns')->insertGetId([
                    'conversation_id' => $id, 'request_id' => $requestId, 'input_hash' => $hash,
                    'created_at' => DB::raw('UTC_TIMESTAMP()'), ...$values,
                ]);
            } else {
                $turnId = $existing->id;
                (clone $query)->where('id', $turnId)->update($values);
            }

            return ['id' => $turnId, 'token' => $token, 'result' => null];
        });
    }

    private function assertCurrent(array $claim): void
    {
        if (! DB::table('ai_conversation_turns')->where('id', $claim['id'])->where('lease_token', $claim['token'])
            ->whereNull('result')->where('lease_expires_at', '>', DB::raw('UTC_TIMESTAMP(6)'))->exists()) {
            throw new AiAgentException('turn_expired');
        }
    }

    private function finish(User $owner, string $id, array $claim, array $turn): array
    {
        return DB::transaction(function () use ($owner, $id, $claim, $turn): array {
            $conversation = $owner->aiConversations()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrent($claim);
            $userId = $assistantId = null;
            foreach ($turn['messages'] as $event) {
                $message = $conversation->messages()->create([
                    'role' => $event['role'], 'content' => $event['content'], 'tool_call_id' => $event['call_id'] ?? null,
                    'tool_calls' => $event['role'] === 'assistant' ? ['version' => 1, 'calls' => $event['calls'], 'state' => $event['state']] : null,
                ]);
                if (! $message->exists || $message->getKey() === null) {
                    throw new AiAgentException('persistence_error');
                }
                if ($event['role'] === 'user') {
                    $userId = $message->id;
                } elseif ($event['role'] === 'assistant') {
                    $assistantId = $message->id;
                }
            }
            $result = ['conversation_id' => $conversation->id, 'user_message_id' => $userId,
                'assistant_message_id' => $assistantId, 'content' => $turn['answer'], 'tools' => $turn['tools']];
            if (! $conversation->touch()) {
                throw new AiAgentException('persistence_error');
            }
            $updated = DB::table('ai_conversation_turns')->where('id', $claim['id'])->where('lease_token', $claim['token'])
                ->update(['result' => $this->limits->json($result), 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => DB::raw('UTC_TIMESTAMP()')]);
            if ($updated !== 1) {
                throw new AiAgentException('persistence_error');
            }

            return $result;
        });
    }

    private function history(User $owner, string $id): array
    {
        $messages = $this->find($owner, $id)->messages()->whereIn('role', [AiMessageRole::User, AiMessageRole::Assistant, AiMessageRole::Tool])
            ->orderByDesc('id')->limit($this->limits->get('max_history_messages'))->get()->reverse()->values();
        // Drop a leading partial turn instead of replaying orphaned function outputs.
        $messages = $messages->skipUntil(fn ($message) => $message->role === AiMessageRole::User);

        return $messages->map(fn ($message) => ['role' => $message->role->value, 'content' => $message->content,
            'calls' => ($message->tool_calls['version'] ?? null) === 1 ? $message->tool_calls['calls'] : [],
            'state' => ($message->tool_calls['version'] ?? null) === 1 ? $message->tool_calls['state'] : [],
            'call_id' => $message->tool_call_id])->values()->all();
    }
}
