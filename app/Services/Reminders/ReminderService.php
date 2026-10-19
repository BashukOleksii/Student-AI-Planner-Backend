<?php

namespace App\Services\Reminders;

use App\Enums\ReminderStatus;
use App\Models\Reminder;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

class ReminderService
{
    public function __construct(private ReminderRules $rules, private ReminderTargetService $targets, private ReminderMutationService $mutations) {}

    public function listing(User $owner, array $filters): LengthAwarePaginator
    {
        $query = $owner->reminders()->orderBy('trigger_at')->orderBy('id');
        foreach (['status', 'trigger_from', 'trigger_to'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field === 'status' ? 'status' : 'trigger_at', match ($field) {
                    'trigger_from' => '>=', 'trigger_to' => '<=', default => '=',
                }, $field === 'status' ? $filters[$field] : $this->databaseTime($filters[$field]));
            }
        }
        $page = $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        foreach ($page->items() as $reminder) {
            $this->integrity($owner, $reminder);
        }
        $page->appends(array_diff_key($filters, ['page' => true]));

        return $page;
    }

    public function find(User $owner, string $id): Reminder
    {
        $reminder = $owner->reminders()->whereKey($id)->firstOrFail();
        Gate::forUser($owner)->authorize('view', $reminder);
        $this->integrity($owner, $reminder);

        return $reminder;
    }

    public function create(User $user, array $data): Reminder
    {
        return $this->transaction($user, fn (User $owner) => $this->mutations->create($owner, $data));
    }

    public function update(User $user, string $id, array $data): Reminder
    {
        return $this->transaction($user, fn (User $owner) => $this->mutations->update($owner, $id, $data));
    }

    public function cancel(User $user, string $id): Reminder
    {
        return $this->transaction($user, fn (User $owner) => $this->mutations->cancel($owner, $id));
    }

    private function transaction(User $user, Closure $action): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reminder mutation requires an outermost transaction.');
        }

        return DB::transaction(function () use ($user, $action): mixed {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $action($owner);
        });
    }

    private function integrity(User $owner, Reminder $reminder, bool $lock = false): ?array
    {
        if ($reminder->status !== ReminderStatus::Scheduled) {
            return null; // Historical targets are preserved without repair or eligibility checks.
        }

        return $this->targets->forReminder($owner, $reminder, $lock);
    }

    private function databaseTime(string $trigger): string
    {
        return $this->rules->databaseTime($trigger);
    }
}
