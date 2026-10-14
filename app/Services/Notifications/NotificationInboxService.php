<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Services\Reminders\ReminderDeliveryRules;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

class NotificationInboxService
{
    public function __construct(private ReminderDeliveryRules $payloads) {}

    public function listing(User $owner, array $filters): LengthAwarePaginator
    {
        $query = $owner->notifications()->reorder()->orderByDesc('created_at')->orderByDesc('id');
        if (($filters['unread_only'] ?? 'false') === 'true') {
            $query->whereNull('read_at');
        }
        $page = $query->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $page->appends(array_diff_key($filters, ['page' => true]));
        foreach ($page->items() as $notification) {
            $this->validate($notification);
        }

        return $page;
    }

    public function read(User $user, string $id): DatabaseNotification
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Notification read mutation requires an outermost transaction.');
        }

        return DB::transaction(function () use ($user, $id): DatabaseNotification {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $notification = $owner->notifications()->whereKey($id)->lockForUpdate()->firstOrFail();
            Gate::forUser($owner)->authorize('update', $notification);
            $this->validate($notification);
            if ($notification->read_at === null) {
                $notification->read_at = CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
                if (! $notification->save()) {
                    throw new LogicException('The notification could not be marked read.');
                }
            }

            return $notification;
        });
    }

    private function validate(DatabaseNotification $notification): void
    {
        if ($notification->type === ReminderDeliveryRules::TYPE) {
            $this->payloads->stored($notification->data, $notification->reminder_id);
        }
    }
}
