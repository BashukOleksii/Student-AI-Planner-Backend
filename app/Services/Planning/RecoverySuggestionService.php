<?php

namespace App\Services\Planning;

use App\Exceptions\RecoverySuggestionException;
use App\Exceptions\StudyPlanConflictException;
use App\Http\Resources\StudyPlanResource;
use App\Http\Resources\StudyReplanningConfirmationResource;
use App\Models\RecoverySuggestion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Throwable;

/** Durable owner reviews around existing planning tokens; never a second allocation engine. */
class RecoverySuggestionService
{
    public function __construct(private RecoverySourceStateService $sources, private LearningRecoveryAnalysisService $analysis,
        private StudyPlanPersistenceService $plans, private StudyReplanningPersistenceService $replanning) {}

    public function scan(int $limit = 10, int $days = 7, ?int $userId = null): array
    {
        if ($limit < 1 || $limit > 100 || $days < 1 || $days > 31 || ($userId !== null && $userId < 1)) {
            throw new \InvalidArgumentException;
        }
        $ids = User::query()->leftJoin('recovery_scan_states', 'users.id', '=', 'recovery_scan_states.user_id')
            ->where(fn ($query) => $query->whereHas('tasks', fn ($q) => $q->whereIn('status', ['pending', 'missed']))
                ->orWhereHas('studySessions', fn ($q) => $q->where('status', 'planned'))
                ->orWhereHas('recoverySuggestions', fn ($q) => $q->where('status', 'pending')))
            ->when($userId !== null, fn ($query) => $query->where('users.id', $userId))
            ->orderByRaw('recovery_scan_states.last_scanned_at IS NOT NULL')->orderBy('recovery_scan_states.last_scanned_at')
            ->orderBy('users.id')->limit($limit)->pluck('users.id');
        $counts = ['selected' => $ids->count(), 'created' => 0, 'unchanged' => 0, 'no_need' => 0, 'limited' => 0, 'failed' => 0];
        foreach ($ids as $id) {
            try {
                $counts[$this->detect((int) $id, $days)]++;
            } catch (Throwable $exception) {
                $limited = $exception instanceof RecoverySuggestionException && $exception->errorCode === 'analysis_limit_exceeded';
                $counts[$limited ? 'limited' : 'failed']++;
                // A failed owner must not starve later owners on every bounded scheduler invocation.
                try {
                    DB::transaction(function () use ($id, $limited): void {
                        $owner = User::whereKey($id)->lockForUpdate()->first();
                        if ($owner !== null) {
                            if ($limited) {
                                $this->invalidateActive($owner);
                            }
                            $this->recordScan($owner, null, $limited ? 'analysis_limit_exceeded' : 'failed');
                        }
                    });
                } catch (Throwable) {
                    // No exception contents, credentials or source data are emitted by this command.
                }
            }
        }

        return $counts;
    }

    public function detect(int $ownerId, int $days = 7): string
    {
        $this->outermost();
        if ($days < 1 || $days > 31) {
            throw new \InvalidArgumentException;
        }

        return DB::transaction(fn () => $this->detectWithinTransaction($ownerId, $days));
    }

    /** Compose detection atomically with an owner-first application transaction; never applies a plan. */
    public function detectWithinTransaction(int $ownerId, int $days = 7): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Recovery detection requires an existing owner-first transaction.');
        }
        if ($days < 1 || $days > 31) {
            throw new \InvalidArgumentException;
        }
        $owner = User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
        [$start, $end] = $this->period($owner, $days);
        $source = $this->sources->capture($owner, $start, $end);
        $hash = $source['source_hash'];
        $active = $owner->recoverySuggestions()->where('status', 'pending')->lockForUpdate()->first();
        if ($active !== null && ($active->expires_at <= CarbonImmutable::now('UTC') || ! hash_equals($active->source_hash, $hash))) {
            $this->resolve($active, $active->expires_at <= CarbonImmutable::now('UTC') ? 'expired' : 'invalidated');
        }
        $state = DB::table('recovery_scan_states')->where('user_id', $owner->id)->first();
        $latest = $owner->recoverySuggestions()->where('source_hash', $hash)->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($state?->last_source_hash === $hash && $state->last_outcome === 'no_need') {
            $this->recordScan($owner, $hash, 'no_need');

            return 'unchanged';
        }
        if (($active !== null && $active->status === 'pending' && hash_equals($active->source_hash, $hash))
            || ($latest !== null && in_array($latest->kind, ['warning', 'decision_required'], true))) {
            $this->recordScan($owner, $hash, 'unchanged');

            return 'unchanged';
        }
        $result = $this->analysis->analyze($owner, $start, $end);
        if ($result['kind'] === null) {
            $this->recordScan($owner, $hash, 'no_need');

            return 'no_need';
        }
        if ($owner->recoverySuggestions()->where('need_hash', $result['need_hash'])->exists()) {
            $this->recordScan($owner, $result['source_hash'], 'unchanged');

            return 'unchanged';
        }
        $this->store($owner, $start, $end, $result);
        $this->recordScan($owner, $result['source_hash'], 'created');

        return 'created';
    }

    public function listing(User $owner, array $filters)
    {
        return DB::transaction(function () use ($owner, $filters) {
            $query = $owner->recoverySuggestions()->orderByDesc('created_at')->orderByDesc('id');
            $status = $filters['status'] ?? 'pending';
            if ($status !== 'all') {
                $query->where('status', $status);
            }
            $page = $query->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
            foreach ($page->items() as $suggestion) {
                $this->annotate($owner, $suggestion);
            }

            return $page;
        });
    }

    public function show(User $owner, string $id): RecoverySuggestion
    {
        return DB::transaction(fn () => $this->annotate($owner, $owner->recoverySuggestions()->findOrFail($id)));
    }

    public function automation(User $owner): array
    {
        $state = DB::table('recovery_scan_states')->where('user_id', $owner->id)->first();

        return ['last_scanned_at' => $state?->last_scanned_at === null ? null : CarbonImmutable::parse($state->last_scanned_at, 'UTC')->toISOString(),
            'last_outcome' => $state?->last_outcome, 'notification_channel' => 'recovery_review_feed'];
    }

    public function confirm(User $user, string $id, mixed $acceptPartial = false, mixed $acceptUnplacedCancellation = false): array
    {
        $this->outermost();
        foreach (['accept_partial' => $acceptPartial, 'accept_unplaced_cancellation' => $acceptUnplacedCancellation] as $field => $value) {
            if (! is_bool($value)) {
                throw ValidationException::withMessages([$field => ['This field must be a genuine boolean.']]);
            }
        }
        $outcome = DB::transaction(function () use ($user, $id, $acceptPartial, $acceptUnplacedCancellation): array {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = $owner->recoverySuggestions()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'applied') {
                return ['suggestion' => $row, 'replayed' => true];
            }
            if ($row->status !== 'pending') {
                return ['error' => $row->status === 'expired' ? 'suggestion_expired' : 'suggestion_resolved'];
            }
            if ($row->expires_at <= CarbonImmutable::now('UTC')) {
                $this->resolve($row, 'expired');

                return ['error' => 'suggestion_expired'];
            }
            try {
                $current = $this->sources->capture($owner, $row->start_date->toDateString(), $row->end_date->toDateString());
            } catch (RecoverySuggestionException) {
                $this->resolve($row, 'invalidated');

                return ['error' => 'suggestion_stale'];
            }
            if (! hash_equals($row->source_hash, $current['source_hash'])) {
                $this->resolve($row, 'invalidated');

                return ['error' => 'suggestion_stale'];
            }
            if (! in_array($row->kind, ['create_plan', 'replan'], true)) {
                return ['error' => 'decision_required'];
            }
            if ($row->kind !== 'replan' && $acceptUnplacedCancellation) {
                throw ValidationException::withMessages(['accept_unplaced_cancellation' => ['Cancellation consent is only valid for adaptive replanning.']]);
            }
            try {
                // Failed writers roll back to a savepoint before terminal review metadata is recorded.
                $receipt = DB::transaction(function () use ($owner, $row, $acceptPartial, $acceptUnplacedCancellation): array {
                    $result = $row->kind === 'replan'
                        ? $this->replanning->confirmWithinTransaction($owner, $row->protected_token, $acceptPartial, $acceptUnplacedCancellation)
                        : $this->plans->confirmWithinTransaction($owner, $row->protected_token, $acceptPartial);
                    if ($row->expires_at <= CarbonImmutable::now('UTC')) {
                        throw new RecoverySuggestionException('suggestion_expired');
                    }
                    $resource = $row->kind === 'replan' ? new StudyReplanningConfirmationResource($result) : new StudyPlanResource($result);

                    return ['suggestion_id' => $row->id, 'kind' => $row->kind, 'applied_at' => CarbonImmutable::now('UTC')->toISOString(),
                        'result' => json_decode(json_encode($resource->resolve(new Request), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR)];
                });
            } catch (StudyPlanConflictException) {
                $this->resolve($row, 'invalidated');

                return ['error' => 'suggestion_stale'];
            } catch (RecoverySuggestionException $exception) {
                $this->resolve($row, 'expired');

                return ['error' => $exception->errorCode];
            } catch (ValidationException $exception) {
                if (array_intersect(array_keys($exception->errors()), ['accept_partial', 'accept_unplaced_cancellation']) !== []) {
                    throw $exception;
                }
                $this->resolve($row, 'invalidated');

                return ['error' => 'suggestion_stale'];
            }
            $row->receipt = $receipt;
            $this->resolve($row, 'applied');

            return ['suggestion' => $row->fresh(), 'replayed' => false];
        });
        if (isset($outcome['error'])) {
            throw new RecoverySuggestionException($outcome['error']);
        }

        return $outcome;
    }

    public function reject(User $user, string $id): RecoverySuggestion
    {
        $this->outermost();

        return DB::transaction(function () use ($user, $id): RecoverySuggestion {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $row = $user->recoverySuggestions()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($row->status === 'applied') {
                throw new RecoverySuggestionException('suggestion_resolved');
            }
            if ($row->status === 'pending') {
                $this->resolve($row, $row->expires_at <= CarbonImmutable::now('UTC') ? 'expired' : 'rejected');
            }

            return $row;
        });
    }

    /** Explicit fresh review, never a business mutation or silent replacement of consented content. */
    public function refresh(User $user, string $id): RecoverySuggestion
    {
        $this->outermost();
        $outcome = DB::transaction(function () use ($user, $id): array {
            $owner = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $old = $owner->recoverySuggestions()->whereKey($id)->lockForUpdate()->firstOrFail();
            $child = $owner->recoverySuggestions()->where('refreshed_from_id', $old->id)->first();
            if ($child !== null) {
                return ['suggestion' => $this->annotate($owner, $child)];
            }
            if ($old->status === 'applied') {
                throw new RecoverySuggestionException('suggestion_resolved');
            }
            $days = min(31, (int) $old->start_date->diffInDays($old->end_date) + 1);
            [$start, $end] = $this->period($owner, $days);
            $result = $this->analysis->analyze($owner, $start, $end);
            $this->invalidateActive($owner);
            if ($result['kind'] === null) {
                return ['error' => 'no_recovery_needed'];
            }

            return ['suggestion' => $this->store($owner, $start, $end, $result, $old->id)];
        });
        if (isset($outcome['error'])) {
            throw new RecoverySuggestionException($outcome['error']);
        }

        return $outcome['suggestion'];
    }

    private function store(User $owner, string $start, string $end, array $result, ?string $from = null): RecoverySuggestion
    {
        $this->invalidateActive($owner);
        $row = $owner->recoverySuggestions()->create(['id' => (string) Str::uuid(), 'active_owner_id' => $owner->id,
            'dedupe_key' => $this->sources->hash([$owner->id, $result['need_hash'], $from === null ? 'automatic' : $from]),
            'source_hash' => $result['source_hash'], 'need_hash' => $result['need_hash'], 'refreshed_from_id' => $from, 'kind' => $result['kind'], 'status' => 'pending',
            'start_date' => $start, 'end_date' => $end, 'review' => $result['review'], 'protected_token' => $result['protected_token'],
            'expires_at' => CarbonImmutable::parse($result['expires_at'])->utc()]);
        if (! $row->exists) {
            throw new LogicException('Recovery review persistence failed.');
        }

        return $row;
    }

    private function annotate(User $owner, RecoverySuggestion $row): RecoverySuggestion
    {
        $row->currentStatus = $row->status;
        if ($row->status === 'pending') {
            if ($row->expires_at <= CarbonImmutable::now('UTC')) {
                $row->currentStatus = 'expired';
            } else {
                try {
                    $current = $this->sources->capture($owner, $row->start_date->toDateString(), $row->end_date->toDateString());
                    if (! hash_equals($row->source_hash, $current['source_hash'])) {
                        $row->currentStatus = 'invalidated';
                    }
                } catch (RecoverySuggestionException) {
                    $row->currentStatus = 'invalidated';
                }
            }
        }

        return $row;
    }

    private function invalidateActive(User $owner): void
    {
        foreach ($owner->recoverySuggestions()->where('status', 'pending')->lockForUpdate()->get() as $row) {
            $this->resolve($row, $row->expires_at <= CarbonImmutable::now('UTC') ? 'expired' : 'invalidated');
        }
    }

    private function resolve(RecoverySuggestion $row, string $status): void
    {
        $row->status = $status;
        $row->active_owner_id = null;
        $row->resolved_at = CarbonImmutable::now('UTC');
        $row->protected_token = null;
        if (! $row->save()) {
            throw new LogicException('Recovery review resolution failed.');
        }
    }

    private function recordScan(User $owner, ?string $hash, string $outcome): void
    {
        DB::table('recovery_scan_states')->updateOrInsert(['user_id' => $owner->id], [
            'last_source_hash' => $hash, 'last_outcome' => $outcome, 'last_scanned_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')]);
    }

    private function period(User $owner, int $days): array
    {
        $start = CarbonImmutable::now($owner->timezone)->startOfDay();

        return [$start->toDateString(), $start->addDays($days - 1)->toDateString()];
    }

    private function outermost(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Recovery suggestion decisions require an outermost transaction.');
        }
    }
}
