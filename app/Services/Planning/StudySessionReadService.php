<?php

namespace App\Services\Planning;

use App\Models\StudySession;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class StudySessionReadService
{
    private const FIELDS = ['id', 'user_id', 'task_id', 'subtask_id', 'starts_at', 'ends_at', 'status', 'completed_at', 'actual_minutes'];

    public function __construct(private PlanningContextService $context) {}

    public function range(User $user, string $startDate, string $endDate): array
    {
        $owner = $user->newQuery()->whereKey($user->getKey())->firstOrFail(['id', 'timezone']);
        $coverage = $this->context->dateIntervals($startDate, $endDate, $owner->timezone);
        $sessions = $coverage === [] ? new Collection : $owner->studySessions()
            ->where(function ($query) use ($coverage): void {
                foreach ($coverage as $range) {
                    $query->orWhere(fn ($part) => $part->where('starts_at', '<', $this->context->queryTimestamp($range['end']))
                        ->where('ends_at', '>', $this->context->queryTimestamp($range['start'])));
                }
            })
            ->orderBy('starts_at')->orderBy('id')->get(self::FIELDS);
        $this->context->validateSessionReferences($owner, $sessions);

        return ['sessions' => $sessions, 'meta' => ['start_date' => $startDate, 'end_date' => $endDate, 'timezone' => $owner->timezone]];
    }

    public function find(User $user, string $id): StudySession
    {
        $session = $user->studySessions()->whereKey($id)->firstOrFail(self::FIELDS);
        $this->context->validateSessionReferences($user, new Collection([$session]));

        return $session;
    }
}
