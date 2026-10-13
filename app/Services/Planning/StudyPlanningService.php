<?php

namespace App\Services\Planning;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnexpectedValueException;

/** Read-only Stage A → shared C9 context → deterministic projected plan orchestration. */
class StudyPlanningService
{
    public function __construct(
        private PlanningWorkSelectionService $selection,
        private PlanningContextService $context,
        private StudySessionAllocationService $allocator,
    ) {}

    /**
     * Dates are exact inclusive local calendar labels, at most 31, in the persisted timezone.
     * IDs are null or positive PHP integers; a subtask requires its explicit parent.
     * Sessions remain advisory arrays, emitted in Stage A work order and then slot order.
     *
     * @return array{as_of_utc: string, start_date: string, end_date: string, timezone: string, sessions: list<array>, work_results: list<array>, excluded_items: list<array>, warnings: list<array>, summary: array}
     *
     * @throws InvalidArgumentException For malformed inputs, configuration or unrepresentable arithmetic.
     * @throws ModelNotFoundException For missing, foreign, deleted or incorrectly nested work.
     * @throws UnexpectedValueException For incompatible owned source relationships, without private details.
     */
    public function plan(User $user, string $startDate, string $endDate, mixed $taskId = null, mixed $subtaskId = null): array
    {
        $this->context->validateDates($startDate, $endDate);
        $user = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
        $selection = $this->selection->select($user, $taskId, $subtaskId);
        $context = $this->context->load($user, $startDate, $endDate);
        $clock = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $selection['as_of_utc'], new DateTimeZone('UTC'));
        if ($clock === false || $clock->format('Y-m-d\TH:i:s.u\Z') !== $selection['as_of_utc']) {
            throw new InvalidArgumentException('Invalid Stage A planning clock.');
        }
        $notBefore = $clock->getTimestamp();
        if ($clock->format('u') !== '000000') {
            if ($notBefore === PHP_INT_MAX) {
                throw new InvalidArgumentException('The planning clock cannot be rounded to an integer second.');
            }
            $notBefore++;
        }
        $plan = $this->allocator->allocate($selection['work_items'], $context, $notBefore);

        return ['as_of_utc' => $selection['as_of_utc'], 'start_date' => $startDate, 'end_date' => $endDate, 'timezone' => $user->timezone,
            'sessions' => $plan['sessions'], 'work_results' => $plan['work_results'],
            'excluded_items' => $selection['excluded_items'], 'warnings' => $selection['warnings'], 'summary' => $plan['summary']];
    }
}
