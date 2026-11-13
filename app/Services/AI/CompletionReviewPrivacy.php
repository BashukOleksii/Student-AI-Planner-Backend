<?php

namespace App\Services\AI;

use stdClass;

/** Strip consent keys from structured AI output, never from arbitrary message text. */
class CompletionReviewPrivacy
{
    public static function structured(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = clone $value;
            unset($value->completion_token);
            foreach ($value as $key => $item) {
                $value->$key = self::structured($item);
            }
        } elseif (is_array($value)) {
            unset($value['completion_token']);
            foreach ($value as $key => $item) {
                $value[$key] = self::structured($item);
            }
        }

        return $value;
    }

    public static function toolContent(?string $content): ?string
    {
        $decoded = json_decode($content ?? '');
        if (! $decoded instanceof stdClass) {
            return $content;
        }
        $public = self::structured($decoded);

        return $public == $decoded ? $content : json_encode($public, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function history(array $history): array
    {
        return array_map(function (array $event): array {
            if (($event['role'] ?? null) === 'tool') {
                $event['content'] = self::toolContent($event['content'] ?? null);
            }
            foreach (['calls', 'state'] as $key) {
                if (isset($event[$key])) {
                    $event[$key] = self::structured($event[$key]);
                }
            }

            return $event;
        }, $history);
    }
}
