<?php

namespace Tests\Unit;

use App\Services\AI\CompletionReviewPrivacy;
use PHPUnit\Framework\TestCase;

class CompletionReviewPrivacyTest extends TestCase
{
    public function test_structured_filter_preserves_every_other_review_field_without_mutating_original(): void
    {
        $review = ['future_sessions_count' => 2, 'message' => 'Буде скасовано дві сесії.',
            'completion_token' => 'server-only', 'additional_count' => 3];
        $value = ['preview' => ['completion_review' => $review], 'receipt' => ['review' => $review]];
        $public = CompletionReviewPrivacy::structured($value);
        $expected = ['future_sessions_count' => 2, 'message' => $review['message'], 'additional_count' => 3];
        $this->assertSame($expected, $public['preview']['completion_review']);
        $this->assertSame($expected, $public['receipt']['review']);
        $this->assertSame('server-only', $value['preview']['completion_review']['completion_token']);
    }

    public function test_tool_json_preserves_empty_objects_arrays_and_non_secret_bytes(): void
    {
        $content = '{"ok":true,"tool":"propose_task_complete","data":{"preview":{"completion_review":{"message":"Review","completion_token":"secret","future_sessions_count":1}},"object":{},"list":[]}}';
        $public = CompletionReviewPrivacy::toolContent($content);
        $this->assertStringNotContainsString('completion_token', $public);
        $this->assertStringNotContainsString('secret', $public);
        $this->assertStringContainsString('"object":{}', $public);
        $this->assertStringContainsString('"list":[]', $public);
        $this->assertSame(1, json_decode($public)->data->preview->completion_review->future_sessions_count);
        $this->assertSame($public, CompletionReviewPrivacy::toolContent($public));
        $safe = "{\n  \"ok\": true, \"tool\": \"list_tasks\", \"data\": {}\n}";
        $this->assertSame($safe, CompletionReviewPrivacy::toolContent($safe));
    }

    public function test_history_filters_tool_objects_and_structured_metadata_but_keeps_free_text_and_opaque_state(): void
    {
        $text = 'The text completion_token and server-only is deliberate free text.';
        $opaque = ['provider' => 'openai', 'items' => [['type' => 'reasoning', 'encrypted_content' => 'opaque-signed-content']]];
        $history = [
            ['role' => 'user', 'content' => $text],
            ['role' => 'assistant', 'content' => $text, 'calls' => [], 'state' => $opaque],
            ['role' => 'tool', 'call_id' => 'complete', 'content' => '{"ok":true,"tool":"propose_subtask_complete","data":{"preview":{"completion_review":{"completion_token":"server-only","message":"Review"}}}}'],
        ];
        $public = CompletionReviewPrivacy::history($history);
        $this->assertSame($text, $public[0]['content']);
        $this->assertSame($text, $public[1]['content']);
        $this->assertSame($opaque, $public[1]['state']);
        $this->assertSame('complete', $public[2]['call_id']);
        $this->assertStringNotContainsString('completion_token', $public[2]['content']);
        $this->assertStringContainsString('completion_token', $history[2]['content']);
    }
}
