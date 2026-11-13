<?php

namespace App\Services\Planning;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Small response, one bounded freshness read; never runs allocation or stores review metadata. */
class PlanningAttentionStatusService
{
    public function __construct(private RecoverySourceStateService $sources, private RecoverySuggestionService $suggestions) {}

    public function status(User $owner): array
    {
        return DB::transaction(function () use ($owner): array {
            $state = DB::table('recovery_scan_states')->where('user_id', $owner->id)->first();
            $counts = ['decision_required' => 0, 'replan' => 0, 'warning' => 0, 'create_plan' => 0];
            $pending = $owner->recoverySuggestions()->where('status', 'pending')->get([
                'id', 'user_id', 'status', 'kind', 'source_hash', 'start_date', 'end_date', 'expires_at',
            ]);
            $lastSuccess = $state?->last_successful_at;
            if ($lastSuccess === null && in_array($state?->last_outcome, ['created', 'unchanged', 'no_need'], true)) {
                $lastSuccess = $state->last_scanned_at;
            }
            $failed = $state?->last_error_code !== null || (($state?->requested_version ?? 0) === 0 && in_array($state?->last_outcome, ['failed', 'analysis_limit_exceeded'], true));
            $dirty = $state !== null && $state->requested_version > $state->checked_version;
            $check = $failed ? 'failed' : ($dirty ? 'pending' : ($lastSuccess === null ? 'not_checked' : 'idle'));
            if (! $failed && $dirty && $state->running_at !== null
                && CarbonImmutable::parse($state->queued_until, 'UTC') > CarbonImmutable::now('UTC')) {
                $check = 'running';
            }
            $unverified = $pending->count();
            $refreshRequired = false;
            if ($check === 'idle') {
                try {
                    if ($pending->isNotEmpty()) {
                        foreach ($pending as $row) {
                            $this->suggestions->annotate($owner, $row);
                            if ($row->currentStatus === 'pending') {
                                $counts[$row->kind]++;
                                $unverified--;
                            }
                        }
                        if ($unverified > 0) {
                            $check = 'pending';
                        }
                    } else {
                        $start = CarbonImmutable::now($owner->timezone)->startOfDay();
                        $current = $this->sources->capture($owner, $start->toDateString(), $start->addDays(6)->toDateString());
                        if (! hash_equals($state->last_source_hash ?? '', $current['source_hash'])) {
                            $check = 'pending';
                        } elseif ($state->last_outcome !== 'no_need') {
                            // Existing deduplication intentionally requires explicit refresh after expiry.
                            // A completed scan must not turn that stale review into a false 'all clear'.
                            $latest = $owner->recoverySuggestions()->where('source_hash', $current['source_hash'])
                                ->orderByDesc('created_at')->orderByDesc('id')->first(['status']);
                            $refreshRequired = in_array($latest?->status, ['expired', 'invalidated'], true);
                            $unverified = $refreshRequired ? 1 : 0;
                        }
                    }
                } catch (Throwable) {
                    $check = 'failed';
                    $failed = true;
                }
            }
            $kind = collect($counts)->filter(fn ($count) => $count > 0)->keys()->first();

            return ['attention_count' => $counts['decision_required'] + $counts['replan'] + $counts['warning'],
                'most_important_kind' => $kind, 'counts' => $counts,
                'has_informational_recommendations' => $counts['create_plan'] > 0,
                'check_state' => $check, 'check_pending' => in_array($check, ['pending', 'running', 'not_checked'], true),
                'last_successful_check_at' => $lastSuccess === null ? null : CarbonImmutable::parse($lastSuccess, 'UTC')->toISOString(),
                'check_failed' => $failed, 'unverified_suggestion_count' => $unverified, 'review_refresh_required' => $refreshRequired];
        });
    }
}
