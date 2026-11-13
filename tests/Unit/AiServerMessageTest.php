<?php

namespace Tests\Unit;

use App\Services\AI\AiServerMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiServerMessageTest extends TestCase
{
    public function test_only_typed_provenance_is_public_and_unknown_metadata_is_not_forwarded(): void
    {
        $id = '10000000-0000-4000-8000-000000000001';
        $this->assertSame(['kind' => 'proposal_ready', 'action_id' => $id], AiServerMessage::read([
            'kind' => 'proposal_ready', 'action_id' => $id, 'private' => 'not public',
        ]));
        $this->assertSame(['kind' => 'proposal_not_created'], AiServerMessage::read([
            'kind' => 'proposal_not_created', 'private' => 'not public',
        ]));
    }

    public static function invalidMarkers(): array
    {
        return [[null], ['Task action proposed. Confirmation is required; no task has changed.'],
            [['kind' => 'assistant']], [['kind' => 'proposal_ready']],
            [['kind' => 'proposal_ready', 'action_id' => 'untrusted-id']],
            [['kind' => 'proposal_ready', 'action_id' => 1]],
            [['action_id' => '10000000-0000-4000-8000-000000000001']]];
    }

    #[DataProvider('invalidMarkers')]
    public function test_untyped_text_or_incomplete_metadata_cannot_hide_a_meaningful_answer(mixed $value): void
    {
        $this->assertNull(AiServerMessage::read($value));
    }
}
