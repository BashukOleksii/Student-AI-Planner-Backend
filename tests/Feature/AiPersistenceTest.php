<?php

namespace Tests\Feature;

use App\Enums\AiMessageRole;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsMysqlPersistence;
use Tests\TestCase;

class AiPersistenceTest extends TestCase
{
    use AssertsMysqlPersistence, RefreshDatabase;

    public function test_exact_conversation_and_message_schemas(): void
    {
        $this->assertTableDefinition('ai_conversations', [
            'id' => ['bigint unsigned', false, null],
            'user_id' => ['bigint unsigned', false, null],
            'created_at' => ['timestamp', true, null],
            'updated_at' => ['timestamp', true, null],
        ]);
        $this->assertTableDefinition('ai_messages', [
            'id' => ['bigint unsigned', false, null],
            'conversation_id' => ['bigint unsigned', false, null],
            'role' => ['varchar(20)', false, null],
            'content' => ['longtext', true, null],
            'tool_calls' => ['json', true, null],
            'tool_call_id' => ['varchar(255)', true, null],
            'created_at' => ['timestamp', true, null],
        ]);
        $this->assertSame(['system', 'user', 'assistant', 'tool'], array_column(AiMessageRole::cases(), 'value'));
    }

    public function test_factory_relationships_isolation_and_cascades(): void
    {
        $message = AiMessage::factory()->create();
        $conversation = $message->conversation;
        $other = AiMessage::factory()->create();
        $this->assertSame(AiMessageRole::User, $message->role);
        $this->assertNull($message->tool_calls);
        $this->assertNull($message->tool_call_id);
        $this->assertSame('Test message', $message->content);
        $this->assertSame([$conversation->id], $conversation->user->aiConversations->modelKeys());
        $this->assertSame([$message->id], $conversation->messages->modelKeys());
        $this->assertSame([$other->conversation_id], $other->conversation->user->aiConversations->modelKeys());
        $this->assertTrue($message->conversation->is($conversation));
        $this->assertTrue($conversation->user->aiConversations()->first()->user->is($conversation->user));
        $conversation->delete();
        $this->assertModelMissing($message);
        $this->assertModelExists($other);
        $otherConversation = $other->conversation;
        $otherConversation->user->delete();
        $this->assertModelMissing($otherConversation);
        $this->assertModelMissing($other);
    }

    #[DataProvider('roles')]
    public function test_mysql_accepts_roles_and_model_casts_them(string $role): void
    {
        $message = AiMessage::factory()->create(['role' => $role])->refresh();
        $this->assertSame(AiMessageRole::from($role), $message->role);
        DB::table('ai_messages')->where('id', $message->id)->update(['role' => $role]);
        $this->assertSame(AiMessageRole::from($role), $message->refresh()->role);
    }

    public static function roles(): array
    {
        return [['system'], ['user'], ['assistant'], ['tool']];
    }

    #[DataProvider('invalidRoles')]
    public function test_mysql_rejects_unsupported_roles(string $role): void
    {
        $this->assertInsertAndUpdateRejected(AiMessage::factory()->create(), ['role' => $role], 3819, 'ai_messages_valid_role');
    }

    public static function invalidRoles(): array
    {
        return [['unsupported'], ['']];
    }

    public function test_nullable_content_json_and_tool_call_metadata_round_trip(): void
    {
        // Representative JSON only; this fixture does not define the future AI tool protocol.
        $calls = [['test_name' => 'Example', 'metadata' => ['text' => 'Українська', 'flags' => [true, false], 'optional' => null]]];
        $message = AiMessage::factory()->create(['content' => null, 'tool_calls' => $calls, 'tool_call_id' => 'test-call'])->refresh();
        $this->assertNull($message->content);
        $this->assertEquals($calls, $message->tool_calls);
        $this->assertIsArray($message->tool_calls);
        $this->assertEquals($calls, json_decode($message->getRawOriginal('tool_calls'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('test-call', $message->tool_call_id);
        $this->assertInsertAndUpdateRejected($message, ['tool_calls' => '{invalid json'], 3140);
        $message->update(['content' => 'Test content', 'tool_calls' => null, 'tool_call_id' => null]);
        $this->assertSame('Test content', $message->refresh()->content);
        $this->assertNull($message->tool_calls);
        $this->assertNull($message->tool_call_id);
    }

    public function test_created_at_is_automatic_and_updates_never_expect_updated_at(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 09:00:00', 'UTC'));
        $message = AiMessage::factory()->create()->refresh();
        $this->assertInstanceOf(Carbon::class, $message->created_at);
        $this->assertSame('2026-10-05 09:00:00', $message->created_at->format('Y-m-d H:i:s'));
        $this->assertNull($message->getUpdatedAtColumn());
        $this->assertArrayNotHasKey('updated_at', $message->getAttributes());
        $this->travel(1)->hour();
        // Append-oriented is a usage convention; Eloquent updates remain compatible with this schema.
        $message->content = 'Changed test content';
        $this->assertTrue($message->save());
        $this->assertSame('Changed test content', $message->refresh()->content);
        $this->assertSame('2026-10-05 09:00:00', $message->created_at->format('Y-m-d H:i:s'));
        $this->assertArrayNotHasKey('updated_at', $message->getAttributes());
        $message->touch();
        $this->assertArrayNotHasKey('updated_at', $message->refresh()->getAttributes());
        $this->travelBack();
    }

    public function test_missing_user_and_conversation_references_are_rejected(): void
    {
        $missingUser = User::factory()->create();
        $missingUser->delete();
        $this->assertInsertAndUpdateRejected(AiConversation::factory()->create(), ['user_id' => $missingUser->id], 1452, 'ai_conversations_user_id_foreign');
        $missingConversation = AiConversation::factory()->create();
        $missingConversation->delete();
        $this->assertInsertAndUpdateRejected(AiMessage::factory()->create(), ['conversation_id' => $missingConversation->id], 1452, 'ai_messages_conversation_id_foreign');
    }

    public function test_keys_indexes_and_named_role_check(): void
    {
        $this->assertForeignKeys('ai_conversations', ['user_id' => ['users', 'cascade']]);
        $this->assertForeignKeys('ai_messages', ['conversation_id' => ['ai_conversations', 'cascade']]);
        $this->assertIndex('ai_conversations', 'ai_conversations_user_id_updated_at_index', ['user_id', 'updated_at']);
        $this->assertIndex('ai_messages', 'ai_messages_conversation_id_created_at_index', ['conversation_id', 'created_at']);
        $this->assertIndex('ai_messages', 'ai_messages_tool_call_id_index', ['tool_call_id']);
        $this->assertEnforcedChecks('ai_conversations', []);
        $this->assertEnforcedChecks('ai_messages', ['ai_messages_valid_role']);
    }
}
