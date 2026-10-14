<?php

namespace Tests\Feature;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Reminders\ReminderDeliveryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class NotificationInboxApiTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T08:00:00.500000Z'));
        $this->beforeApplicationDestroyed(fn () => $this->truncateTablesForAllConnections());
    }

    public function test_authentication_and_foreign_access_are_generic_and_unchanged(): void
    {
        $id = (string) Str::uuid();
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->postJson('/api/notifications/'.$id.'/read')->assertUnauthorized();
        [$owner, $reminder, $notification] = $this->fixture();
        $foreign = User::factory()->create();
        $this->actingAs($foreign);
        $this->getJson('/api/notifications')->assertOk()->assertJsonCount(0, 'data');
        foreach ([$id, $notification->id] as $id) {
            $this->postJson('/api/notifications/'.$id.'/read', ['read_at' => 'invalid'])->assertNotFound()->assertExactJson(['message' => 'Not found.']);
        }
        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame(ReminderStatus::Sent, $reminder->fresh()->status);
        $this->assertFalse(Gate::forUser($foreign)->allows('update', $notification));
    }

    public function test_owned_list_representation_filters_pagination_and_stable_order_do_not_write(): void
    {
        [$owner, $reminder, $notification] = $this->fixture();
        $this->actingAs($owner);
        $second = Reminder::factory()->for($owner)->create(['message' => 'Second', 'trigger_at' => '2026-10-18 08:00:00']);
        app(ReminderDeliveryService::class)->process($owner->id, $second->id);
        $sameTime = $owner->notifications()->orderByDesc('id')->pluck('id')->all();
        $snapshot = DatabaseNotification::orderBy('id')->get()->map->getAttributes()->all();
        $this->assertSame($sameTime, $this->getJson('/api/notifications')->assertOk()->json('data.*.id'));
        $data = $this->getJson('/api/notifications')->assertOk()->json('data.0');
        $this->assertSame(['id', 'type', 'reminder_id', 'message', 'task_id', 'subtask_id', 'study_session_id', 'trigger_at', 'created_at', 'read_at'], array_keys($data));
        $this->assertSame('reminder', $data['type']);
        $this->assertSame('2026-10-19T08:00:00Z', $data['created_at']);
        $this->assertNull($data['read_at']);
        $this->getJson('/api/notifications?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $sameTime[1]);
        $this->assertSame($snapshot, DatabaseNotification::orderBy('id')->get()->map->getAttributes()->all());
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->getJson('/api/notifications?unread_only=true')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/notifications?unread_only=false')->assertOk()->assertJsonCount(2, 'data');
        foreach (['?unread_only=1', '?unread_only=TRUE', '?per_page=101', '?per_page=1.0', '?per_page=0', '?page=0', '?user_id=1', '?unread_only[]=true', '?page=999999999999999999999'] as $query) {
            $this->getJson('/api/notifications'.$query)->assertUnprocessable();
        }
    }

    public function test_mark_read_is_idempotent_and_never_changes_payload_or_reminder(): void
    {
        [$owner, $reminder, $notification] = $this->fixture();
        $this->actingAs($owner);
        $before = $reminder->fresh()->getAttributes();
        $payload = $notification->fresh()->data;
        $this->travelTo(CarbonImmutable::parse('2026-10-19T09:10:11.654321Z'));
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk()->assertJsonPath('data.read_at', '2026-10-19T09:10:11Z');
        $read = $notification->fresh()->getAttributes();
        $this->travelTo(CarbonImmutable::parse('2026-10-20T00:00:00Z'));
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk()->assertJsonPath('data.read_at', '2026-10-19T09:10:11Z');
            $this->assertSame([], collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_starts_with($sql, 'update '))->all());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertSame($read, $notification->fresh()->getAttributes());
        $this->assertSame($payload, $notification->fresh()->data);
        $this->assertSame($before, $reminder->fresh()->getAttributes());
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_read_body_is_empty_and_query_cannot_override_server_timestamp(): void
    {
        [$owner, , $notification] = $this->fixture();
        $this->actingAs($owner);
        foreach (['read_at', 'data', 'user_id', 'notifiable_id', 'reminder_id', 'type', 'unknown'] as $field) {
            $this->postJson('/api/notifications/'.$notification->id.'/read', [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach (['null', 'false', '42', '{bad', '[1,2]'] as $body) {
            $this->call('POST', '/api/notifications/'.$notification->id.'/read', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertUnprocessable();
        }
        $this->assertNull($notification->fresh()->read_at);
        $this->postJson('/api/notifications/'.$notification->id.'/read?read_at=2000-01-01')->assertOk()->assertJsonPath('data.read_at', '2026-10-19T08:00:00Z');
    }

    public function test_history_survives_source_changes_and_physical_purge_without_rewriting_payload(): void
    {
        [$owner, $reminder, $notification] = $this->fixture();
        $this->actingAs($owner);
        $payload = $notification->data;
        $reminder->delete();
        $this->getJson('/api/notifications')->assertOk()->assertJsonPath('data.0.reminder_id', $reminder->id)->assertJsonPath('data.0.message', $payload['message']);
        $this->postJson('/api/notifications/'.$notification->id.'/read')->assertOk();
        $this->assertSame($payload, $notification->fresh()->data);
        $this->assertNull($notification->fresh()->reminder_id);
    }

    public function test_future_notification_types_and_foreign_morph_types_are_safe(): void
    {
        [$owner] = $this->fixture();
        $this->actingAs($owner);
        $future = $owner->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'Private\\FutureType', 'data' => ['password' => 'secret', 'task_id' => 999, 'message' => 'Private metadata']]);
        $otherType = DatabaseNotification::create(['id' => (string) Str::uuid(), 'type' => 'other', 'notifiable_type' => 'Other\\Recipient', 'notifiable_id' => $owner->id, 'data' => []]);
        $response = $this->getJson('/api/notifications')->assertOk()->assertJsonCount(2, 'data');
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('Private', $response->getContent());
        $item = collect($response->json('data'))->firstWhere('id', $future->id);
        $this->assertSame('notification', $item['type']);
        $this->assertNull($item['task_id']);
        $this->postJson('/api/notifications/'.$otherType->id.'/read')->assertNotFound();
    }

    #[DataProvider('readFailures')]
    public function test_corrupt_payload_and_failed_read_save_roll_back(string $failure): void
    {
        [$owner, , $notification] = $this->fixture();
        $this->actingAs($owner);
        if ($failure === 'corruption') {
            $notification->update(['data' => ['reminder_id' => 999, 'password' => 'secret']]);
        } elseif ($failure === 'veto') {
            DatabaseNotification::saving(fn () => false);
        } else {
            DatabaseNotification::updated(fn () => throw new RuntimeException('Private injected write failure.'));
        }
        $before = $notification->fresh()->getAttributes();
        try {
            $this->postJson('/api/notifications/'.$notification->id.'/read')->assertStatus(500)->assertExactJson(['message' => 'The notification could not be processed.']);
            $this->assertSame($before, $notification->fresh()->getAttributes());
            if ($failure === 'corruption') {
                $this->getJson('/api/notifications')->assertStatus(500)->assertExactJson(['message' => 'The notification could not be processed.']);
            }
        } finally {
            DatabaseNotification::flushEventListeners();
        }
    }

    public static function readFailures(): array
    {
        return [['corruption'], ['veto'], ['throw']];
    }

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $reminder = Reminder::factory()->for($owner)->create(['message' => 'Review notes']);
        app(ReminderDeliveryService::class)->process($owner->id, $reminder->id);

        return [$owner, $reminder, $owner->notifications()->sole()];
    }
}
