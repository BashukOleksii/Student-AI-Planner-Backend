<?php

namespace App\Services\Planning;

use App\Exceptions\StudyPlanConflictException;
use DateTimeImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use InvalidArgumentException;
use JsonException;

/** Protected E2 review and independent pure batch validation; no persistence or clock reads. */
class StudyReplanningConfirmationService
{
    public const TOKEN_LIFETIME_SECONDS = 900;

    public function __construct(
        private Encrypter $encrypter,
        private StudyPlanConfirmationService $fields,
        private PlanningContextService $context,
        private PlanningConstraintService $constraints,
        private TimeIntervalService $intervals,
    ) {}

    public function issue(int $ownerId, array $parameters, array $review, int $issuedAt): string
    {
        $payload = ['purpose' => 'adaptive-replanning-confirmation', 'version' => 1, 'owner_id' => $ownerId,
            'parameters' => $parameters, 'review' => $review, 'issued_at' => $issuedAt,
            'expires_at' => $issuedAt <= PHP_INT_MAX - self::TOKEN_LIFETIME_SECONDS ? $issuedAt + self::TOKEN_LIFETIME_SECONDS : null];
        $this->validatePayload($payload, $ownerId, $issuedAt);

        return $this->encrypter->encrypt(json_encode($payload, JSON_THROW_ON_ERROR), false);
    }

    public function decode(string $token, int $ownerId, int $now): array
    {
        try {
            $payload = json_decode($this->encrypter->decrypt($token, false), true, 512, JSON_THROW_ON_ERROR);
            $this->validatePayload($payload, $ownerId, $now);
        } catch (DecryptException|JsonException|InvalidArgumentException $exception) {
            throw new InvalidArgumentException('The preview token is invalid or expired.', previous: $exception);
        }

        return $payload;
    }

    private function validatePayload(mixed $payload, int $ownerId, int $now): void
    {
        if ($ownerId < 1 || ! is_array($payload) || count($payload) !== 7
            || ($payload['purpose'] ?? null) !== 'adaptive-replanning-confirmation'
            || ($payload['version'] ?? null) !== 1 || ($payload['owner_id'] ?? null) !== $ownerId
            || ! is_int($payload['issued_at'] ?? null) || $payload['issued_at'] < 0
            || $payload['issued_at'] > PHP_INT_MAX - self::TOKEN_LIFETIME_SECONDS
            || ! is_int($payload['expires_at'] ?? null)
            || $payload['expires_at'] !== $payload['issued_at'] + self::TOKEN_LIFETIME_SECONDS
            || $payload['issued_at'] > $now || $now >= $payload['expires_at']
            || ! is_array($payload['parameters'] ?? null) || count($payload['parameters']) !== 4
            || array_diff(['start_date', 'end_date', 'task_id', 'subtask_id'], array_keys($payload['parameters'])) !== []
            || ! is_string($payload['parameters']['start_date']) || ! is_string($payload['parameters']['end_date'])
            || ! is_array($payload['review'] ?? null) || array_is_list($payload['review'])) {
            throw new InvalidArgumentException('Invalid review payload.');
        }
        $parameters = $payload['parameters'];
        $this->context->validateDates($parameters['start_date'], $parameters['end_date']);
        foreach (['task_id', 'subtask_id'] as $field) {
            if ($parameters[$field] !== null && (! is_int($parameters[$field]) || $parameters[$field] < 1)) {
                throw new InvalidArgumentException('Invalid work scope.');
            }
        }
        if ($parameters['subtask_id'] !== null && $parameters['task_id'] === null) {
            throw new InvalidArgumentException('Invalid work scope.');
        }
        foreach (['as_of_utc', 'start_date', 'end_date', 'timezone', 'session_results', 'released_session_ids',
            'work_results', 'excluded_items', 'warnings', 'summary'] as $field) {
            if (! array_key_exists($field, $payload['review'])) {
                throw new InvalidArgumentException('Incomplete review.');
            }
        }
        if ($payload['review']['start_date'] !== $parameters['start_date'] || $payload['review']['end_date'] !== $parameters['end_date']) {
            throw new InvalidArgumentException('Invalid review scope.');
        }
        $this->affected($payload['review']);
    }

    public function matches(array $reviewed, array $current): bool
    {
        return $this->fields->matches($reviewed, $current);
    }

    /** Validate the exact ordered release set and one replacement per source. */
    public function affected(array $review): array
    {
        if (! is_array($review['session_results'] ?? null) || ! array_is_list($review['session_results'])
            || ! is_array($review['released_session_ids'] ?? null)) {
            throw new InvalidArgumentException('Invalid reviewed sources.');
        }
        PlanningScenarioService::validateIds($review['released_session_ids']);
        $seen = $affected = [];
        foreach ($review['session_results'] as $row) {
            if (! is_array($row) || ! is_int($row['study_session_id'] ?? null) || $row['study_session_id'] < 1
                || isset($seen[$row['study_session_id']]) || ! is_int($row['task_id'] ?? null) || $row['task_id'] < 1
                || ! array_key_exists('subtask_id', $row)
                || ($row['subtask_id'] !== null && (! is_int($row['subtask_id']) || $row['subtask_id'] < 1))
                || ! in_array($row['classification'] ?? null, ['valid', 'cannot_replan', 'requires_replanning'], true)
                || ! is_array($row['original'] ?? null) || ! array_key_exists('replacement', $row)) {
                throw new InvalidArgumentException('Invalid reviewed source.');
            }
            $seen[$row['study_session_id']] = true;
            $original = $this->intervals->durationSeconds($row['original']);
            $this->context->queryTimestamp($row['original']['start']);
            $this->context->queryTimestamp($row['original']['end']);
            if ($row['replacement'] !== null) {
                if (! is_array($row['replacement']) || $row['classification'] !== 'requires_replanning'
                    || ! isset($row['replacement']['start'], $row['replacement']['end'])) {
                    throw new InvalidArgumentException('Invalid replacement.');
                }
                $this->fields->sessionAttributes(1, $row['replacement']);
                if ($row['replacement']['task_id'] !== $row['task_id'] || $row['replacement']['subtask_id'] !== $row['subtask_id']
                    || $row['replacement']['duration_seconds'] > $original) {
                    throw new InvalidArgumentException('Invalid replacement attribution or duration.');
                }
            }
            if ($row['classification'] === 'requires_replanning') {
                $affected[] = $row;
            }
        }
        if (array_column($affected, 'study_session_id') !== $review['released_session_ids']) {
            throw new InvalidArgumentException('Invalid release set.');
        }

        return $affected;
    }

    public function acceptanceError(array $review, bool $acceptPartial, bool $acceptUnplacedCancellation): ?string
    {
        $affected = $this->affected($review);
        if ($affected === []) {
            return 'preview_token';
        }
        foreach ($affected as $row) {
            if (($row['replacement']['duration_seconds'] ?? 0) < $this->intervals->durationSeconds($row['original']) && ! $acceptPartial) {
                return 'accept_partial';
            }
        }
        foreach ($affected as $row) {
            if ($row['replacement'] === null && ! $acceptUnplacedCancellation) {
                return 'accept_unplaced_cancellation';
            }
        }

        return null;
    }

    /** Context and effort are independently loaded after transactional retirement of ALL sources. */
    public function validateBatch(int $ownerId, array $review, array $workItems, array $context, DateTimeImmutable $now): void
    {
        $affected = $this->affected($review);
        $work = [];
        foreach ($workItems as $item) {
            $work[$item['task_id'].':'.($item['subtask_id'] ?? 'task')] = $item;
        }
        $proposals = array_values(array_filter(array_column($affected, 'replacement')));
        $allocated = [];
        foreach ($proposals as $index => $proposal) {
            $this->fields->sessionAttributes($ownerId, $proposal);
            $key = $proposal['task_id'].':'.($proposal['subtask_id'] ?? 'task');
            if (! isset($work[$key]) || ($allocated[$key] ?? 0) > PHP_INT_MAX - $proposal['duration_seconds']) {
                throw new StudyPlanConflictException;
            }
            $allocated[$key] = ($allocated[$key] ?? 0) + $proposal['duration_seconds'];
            $others = array_map(fn ($row) => ['start' => $row['start'], 'end' => $row['end']],
                array_filter($proposals, fn ($otherIndex) => $otherIndex !== $index, ARRAY_FILTER_USE_KEY));
            if ($allocated[$key] > $work[$key]['effort']['unallocated_work_seconds']
                || $this->constraints->evaluate(['start' => $proposal['start'], 'end' => $proposal['end']],
                    $context['free_intervals'], [...$context['break_anchors'], ...$others],
                    [...$context['workload_sessions'], ...$others], $context['timezone'], $context['preferences'],
                    $work[$key]['effective_deadline_utc']) !== []) {
                throw new StudyPlanConflictException;
            }
        }
        if (! $this->fields->startsAreFuture([...array_column($affected, 'original'), ...$proposals], $now)) {
            throw new StudyPlanConflictException;
        }
    }
}
