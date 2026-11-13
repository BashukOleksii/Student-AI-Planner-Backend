<?php

namespace App\Services\Planning;

use App\Jobs\AnalyzeOwnerPlan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/** Durable per-owner dirty state; analysis is never executed in a mutation response. */
class RecoveryCheckTriggerService
{
    public const DEBOUNCE_SECONDS = 3;

    public const LEASE_SECONDS = 120;

    private array $scheduled = [];

    public function request(int $ownerId): void
    {
        if (isset($this->scheduled[$ownerId])) {
            return;
        }
        DB::transaction(function () use ($ownerId): void {
            if (! User::whereKey($ownerId)->lockForUpdate()->exists()) {
                return;
            }
            DB::table('recovery_scan_states')->insertOrIgnore(['user_id' => $ownerId]);
            DB::table('recovery_scan_states')->where('user_id', $ownerId)->update([
                'requested_version' => DB::raw('requested_version + 1'),
                'requested_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'last_error_code' => null, 'last_error_at' => null,
            ]);
            // One callback per owner/transaction, including large semester imports.
            $this->scheduled[$ownerId] = true;
            DB::afterRollBack(function () use ($ownerId): void {
                unset($this->scheduled[$ownerId]);
            });
            DB::afterCommit(function () use ($ownerId): void {
                unset($this->scheduled[$ownerId]);
                $this->dispatch($ownerId);
            });
        });
    }

    /** A failed dispatch leaves durable dirty state for the existing bounded Scheduler scan. */
    public function dispatch(int $ownerId): void
    {
        $token = null;
        try {
            $token = DB::transaction(function () use ($ownerId): ?string {
                if (! User::whereKey($ownerId)->lockForUpdate()->exists()) {
                    return null;
                }
                $state = DB::table('recovery_scan_states')->where('user_id', $ownerId)->first();
                $now = CarbonImmutable::now('UTC');
                if ($state === null || $state->requested_version <= $state->checked_version
                    || ($state->queued_token !== null && CarbonImmutable::parse($state->queued_until, 'UTC') > $now)) {
                    return null;
                }
                $token = (string) Str::uuid();
                DB::table('recovery_scan_states')->where('user_id', $ownerId)->update([
                    'queued_token' => $token, 'queued_until' => $now->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u'),
                    'running_at' => null,
                ]);

                return $token;
            });
            if ($token !== null) {
                // Explicit durable database connection, even when the application's default is sync.
                Queue::connection('database')->later(self::DEBOUNCE_SECONDS, new AnalyzeOwnerPlan($ownerId, $token));
            }
        } catch (Throwable) {
            try {
                $this->fail($ownerId, 'queue_unavailable', $token);
            } catch (Throwable) {
                // Never turn a successful business commit into an HTTP error.
            }
            Log::warning('Owner plan check could not be queued.', ['owner_id' => $ownerId]);
        }
    }

    /** Return a release delay, null for stale/no-op delivery, or zero to analyze now. */
    public function start(int $ownerId, string $token): ?int
    {
        return DB::transaction(function () use ($ownerId, $token): ?int {
            if (! User::whereKey($ownerId)->lockForUpdate()->exists()) {
                return null;
            }
            $state = DB::table('recovery_scan_states')->where('user_id', $ownerId)->first();
            if ($state === null || $state->queued_token !== $token || $state->requested_version <= $state->checked_version
                || ($state->running_at !== null && CarbonImmutable::parse($state->queued_until, 'UTC') > CarbonImmutable::now('UTC'))) {
                return null;
            }
            $now = CarbonImmutable::now('UTC');
            $due = CarbonImmutable::parse($state->requested_at, 'UTC')->addSeconds(self::DEBOUNCE_SECONDS);
            if ($due > $now) {
                return max(1, (int) ceil($now->diffInSeconds($due)));
            }
            DB::table('recovery_scan_states')->where('user_id', $ownerId)->update([
                'running_at' => $now->format('Y-m-d H:i:s.u'),
                'queued_until' => $now->addSeconds(self::LEASE_SECONDS)->format('Y-m-d H:i:s.u'),
            ]);

            return 0;
        });
    }

    public function fail(int $ownerId, string $code, ?string $token = null): void
    {
        DB::transaction(function () use ($ownerId, $code, $token): void {
            if (! User::whereKey($ownerId)->lockForUpdate()->exists()) {
                return;
            }
            $query = DB::table('recovery_scan_states')->where('user_id', $ownerId);
            if ($token !== null) {
                $query->where('queued_token', $token);
            }
            $query->update(['last_error_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
                'last_error_code' => $code, 'queued_token' => null, 'queued_until' => null, 'running_at' => null]);
        });
    }
}
