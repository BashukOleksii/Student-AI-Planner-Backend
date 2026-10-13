<?php

namespace App\Services\Planning;

use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use JsonException;

/** Protected review contract and pure confirmation checks; no persistence or clock reads. */
class StudyPlanConfirmationService
{
    public const TOKEN_LIFETIME_SECONDS = 900;

    public function __construct(private Encrypter $encrypter, private TimeIntervalService $intervals) {}

    public function issue(int $ownerId, array $parameters, array $plan, int $issuedAt): string
    {
        if ($ownerId < 1 || $issuedAt > PHP_INT_MAX - self::TOKEN_LIFETIME_SECONDS) {
            throw new InvalidArgumentException('Invalid preview issuance parameters.');
        }

        return $this->encrypter->encrypt(json_encode([
            'purpose' => 'study-plan-confirmation', 'version' => 1, 'owner_id' => $ownerId,
            'parameters' => $parameters, 'plan' => $plan,
            'issued_at' => $issuedAt, 'expires_at' => $issuedAt + self::TOKEN_LIFETIME_SECONDS,
        ], JSON_THROW_ON_ERROR), false);
    }

    /** Decrypt JSON without PHP object unserialization; every failure has the same public meaning. */
    public function decode(string $token, int $ownerId, int $now): array
    {
        try {
            $payload = json_decode($this->encrypter->decrypt($token, false), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException $exception) {
            throw new InvalidArgumentException('The preview token is invalid or expired.', previous: $exception);
        }
        if (! is_array($payload) || ($payload['purpose'] ?? null) !== 'study-plan-confirmation'
            || ($payload['version'] ?? null) !== 1 || ($payload['owner_id'] ?? null) !== $ownerId
            || ! is_int($payload['issued_at'] ?? null) || ! is_int($payload['expires_at'] ?? null)
            || $payload['issued_at'] > $now || $now >= $payload['expires_at']
            || $payload['expires_at'] - $payload['issued_at'] !== self::TOKEN_LIFETIME_SECONDS
            || ! is_array($payload['parameters'] ?? null) || ! is_array($payload['plan'] ?? null)
            || array_diff(['start_date', 'end_date', 'task_id', 'subtask_id'], array_keys($payload['parameters'])) !== []
            || count($payload['parameters']) !== 4
            || ! is_string($payload['parameters']['start_date']) || ! is_string($payload['parameters']['end_date'])) {
            throw new InvalidArgumentException('The preview token is invalid or expired.');
        }
        foreach (['task_id', 'subtask_id'] as $field) {
            $id = $payload['parameters'][$field];
            if ($id !== null && (! is_int($id) || $id < 1)) {
                throw new InvalidArgumentException('The preview token is invalid or expired.');
            }
        }
        if ($payload['parameters']['subtask_id'] !== null && $payload['parameters']['task_id'] === null) {
            throw new InvalidArgumentException('The preview token is invalid or expired.');
        }

        return $payload;
    }

    /** Object key order is immaterial; scalar types, list order and every outcome field matter. */
    public function matches(array $reviewed, array $current): bool
    {
        unset($reviewed['as_of_utc'], $current['as_of_utc']);

        return $this->canonical($reviewed) === $this->canonical($current);
    }

    /** Return a validation field requiring correction, or null when the exact plan can be accepted. */
    public function acceptanceError(array $plan, bool $acceptPartial): ?string
    {
        if ($plan['sessions'] === []) {
            return 'preview_token';
        }

        return ! $plan['summary']['fully_allocated'] && ! $acceptPartial ? 'accept_partial' : null;
    }

    public function sessionAttributes(int $ownerId, array $proposal): array
    {
        $interval = ['start' => $proposal['start'], 'end' => $proposal['end']];
        if ($ownerId < 1 || ! is_int($proposal['task_id'] ?? null) || $proposal['task_id'] < 1
            || ! array_key_exists('subtask_id', $proposal)
            || ($proposal['subtask_id'] !== null && (! is_int($proposal['subtask_id']) || $proposal['subtask_id'] < 1))
            || ($proposal['duration_seconds'] ?? null) !== $this->intervals->durationSeconds($interval)) {
            throw new InvalidArgumentException('Invalid proposed session.');
        }

        return ['user_id' => $ownerId, 'task_id' => $proposal['task_id'], 'subtask_id' => $proposal['subtask_id'],
            'starts_at' => $this->databaseTimestamp($interval['start']), 'ends_at' => $this->databaseTimestamp($interval['end']),
            'status' => 'planned', 'completed_at' => null, 'actual_minutes' => null, 'rescheduled_from_session_id' => null];
    }

    public function startsAreFuture(array $sessions, DateTimeImmutable $now): bool
    {
        foreach ($sessions as $session) {
            if ((new DateTimeImmutable('@'.$session['start'])) <= $now) {
                return false;
            }
        }

        return true;
    }

    private function databaseTimestamp(int $seconds): string
    {
        $value = (new DateTimeImmutable('@'.$seconds))->format('Y-m-d H:i:s');
        if (! preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/', $value)
            || $value < '1000-01-01 00:00:00' || $value > '9999-12-31 23:59:59') {
            throw new InvalidArgumentException('Invalid proposed session timestamp.');
        }

        return $value;
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as &$item) {
            $item = $this->canonical($item);
        }
        unset($item);
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
