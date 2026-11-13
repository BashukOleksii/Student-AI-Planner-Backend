<?php

namespace App\Services\Planning;

use App\Exceptions\RecoverySuggestionException;
use App\Http\Resources\StudyPlanResource;
use App\Http\Resources\StudyReplanningResource;
use App\Http\Resources\StudySessionResource;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Current-state orchestration of existing deterministic recovery, allocation and workload services. */
class LearningRecoveryAnalysisService
{
    public const REVIEW_LEAD_SECONDS = 900;

    public const MAX_REVIEW_BYTES = 262144;

    public function __construct(private RecoverySourceStateService $sources, private StudyRecoveryPreviewService $recovery,
        private StudyReplanningPersistenceService $replanning, private WorkloadAdviceService $advice,
        private StudyPlanConfirmationService $planTokens, private StudyReplanningConfirmationService $replanningTokens,
        private PlanningWorkSelectionService $selection) {}

    /** Read-only, one consistent snapshot. Protected tokens are only for the persistence adapter. */
    public function analyze(User $user, string $start, string $end): array
    {
        return DB::transaction(function () use ($user, $start, $end): array {
            $source = $this->sources->capture($user, $start, $end);
            $owner = $source['owner'];
            $notBefore = CarbonImmutable::now('UTC')->getTimestamp() + self::REVIEW_LEAD_SECONDS;
            $recovery = $this->recovery->preview($owner, $start, $end, notBeforeUtc: $notBefore);
            $adaptive = $this->replanning->preview($owner, $start, $end, notBeforeUtc: $notBefore);
            $advice = $this->advice->analyze($owner, $start, $end, $notBefore);
            $effort = $this->selection->select($owner);
            $now = CarbonImmutable::now('UTC');
            $unknown = $source['sessions']->filter(fn ($row) => $row->status->value === 'planned'
                && $row->getRawOriginal('starts_at') <= $now->format('Y-m-d H:i:s'));
            $invalid = array_filter($adaptive['session_results'], fn ($row) => $row['classification'] === 'cannot_replan');
            $excludedNeedsAttention = array_filter($advice['excluded_items'], fn ($row) => ($row['recommendation_codes'] ?? []) !== []);
            $kind = match (true) {
                $unknown->isNotEmpty() => 'decision_required',
                $adaptive['summary']['affected_sessions'] > 0 => 'replan',
                $invalid !== [] => 'warning',
                $recovery['study_plan']['plan']['sessions'] !== [] => 'create_plan',
                $excludedNeedsAttention !== [] || $advice['summary']['unscheduled_seconds'] > 0
                    || $advice['persisted_workload_violations'] !== [] => 'warning',
                default => null,
            };
            $token = $expiry = null;
            $required = [];
            if ($kind === 'create_plan') {
                $token = $recovery['study_plan']['preview_token'];
                $expiry = $recovery['study_plan']['expires_at'];
                if ($this->planTokens->acceptanceError($recovery['study_plan']['plan'], false) === 'accept_partial') {
                    $required[] = 'accept_partial';
                }
            } elseif ($kind === 'replan') {
                $token = $adaptive['preview_token'];
                $expiry = $adaptive['expires_at'];
                if ($this->replanningTokens->acceptanceError($adaptive, false, true, true) === 'accept_partial') {
                    $required[] = 'accept_partial';
                }
                if ($this->replanningTokens->acceptanceError($adaptive, true, false, true) === 'accept_unplaced_cancellation') {
                    $required[] = 'accept_unplaced_cancellation';
                }
                if ($this->replanningTokens->acceptanceError($adaptive, true, true, false) === 'accept_split_replacements') {
                    $required[] = 'accept_split_replacements';
                }
            }
            // A review expires before any source/replacement it would need to move or create starts.
            $starts = $kind === 'create_plan' ? array_column($recovery['study_plan']['plan']['sessions'], 'start') : [];
            if ($kind === 'replan') {
                foreach ($this->replanningTokens->affected($adaptive) as $row) {
                    $starts[] = $row['original']['start'];
                    foreach ($this->replanningTokens->replacements($row) as $replacement) {
                        $starts[] = $replacement['start'];
                    }
                }
            }
            if ($expiry !== null && $starts !== []) {
                $expiry = CarbonImmutable::createFromTimestampUTC(min(CarbonImmutable::parse($expiry)->getTimestamp(), ...$starts))->toISOString();
            }
            $planReview = $this->plain(new StudyPlanResource(['plan' => $recovery['study_plan']['plan']]));
            $adaptiveReview = $this->plain(new StudyReplanningResource(array_diff_key($adaptive, ['preview_token' => true, 'expires_at' => true])));
            $review = ['version' => 1, 'as_of_utc' => $now->toISOString(), 'kind' => $kind,
                'review_lead_seconds' => self::REVIEW_LEAD_SECONDS, 'earliest_proposed_start_at' => CarbonImmutable::createFromTimestampUTC($notBefore)->toISOString(),
                'period' => ['start_date' => $start, 'end_date' => $end, 'timezone' => $owner->timezone],
                'actionable' => in_array($kind, ['create_plan', 'replan'], true),
                'requires_lifecycle_decision' => $unknown->isNotEmpty(), 'required_confirmation_fields' => $required,
                'unknown_session_decisions' => $unknown->map(fn ($row) => $this->plain(new StudySessionResource($row)))->values()->all(),
                'recovery' => $recovery['recovery'], 'new_work_plan' => $planReview,
                'work_effort' => $effort['work_items'],
                'unknown_duration_work' => array_values(array_filter($effort['excluded_items'], fn ($row) => in_array('missing_estimate', $row['reason_codes'], true))),
                'completed_credit_is_not_reported_actual_time' => true,
                'adaptive_replanning' => $adaptiveReview, 'workload_advice' => $advice,
                'reason_codes' => array_values(array_unique([
                    ...($unknown->isNotEmpty() ? ['session_outcome_unknown'] : []),
                    ...($adaptive['summary']['affected_sessions'] > 0 ? ['existing_sessions_require_replanning'] : []),
                    ...($invalid !== [] ? ['sessions_require_manual_review'] : []),
                    ...($excludedNeedsAttention !== [] ? ['excluded_work_requires_input'] : []),
                    ...($advice['summary']['unscheduled_seconds'] > 0 ? ['insufficient_allocation_in_period'] : []),
                    ...($advice['persisted_workload_violations'] !== [] ? ['persisted_workload_exceeded'] : []),
                    ...($kind === 'create_plan' ? ['eligible_remaining_work'] : []),
                ])),
                'limitations' => ['single_deterministic_suggestion', 'not_globally_optimal', 'no_inferred_actual_duration',
                    'replan_before_allocating_additional_work', 'unknown_outcomes_require_explicit_student_decisions']];
            if (strlen(json_encode($review, JSON_THROW_ON_ERROR)) > self::MAX_REVIEW_BYTES) {
                throw new RecoverySuggestionException('analysis_limit_exceeded');
            }

            if (! hash_equals($source['source_hash'], $this->sources->capture($owner, $start, $end)['source_hash'])) {
                throw new RecoverySuggestionException('suggestion_stale');
            }
            if ($expiry !== null && CarbonImmutable::parse($expiry) <= CarbonImmutable::now('UTC')) {
                throw new RecoverySuggestionException('suggestion_expired');
            }

            return ['source_hash' => $source['source_hash'],
                'need_hash' => $this->sources->hash([$source['source_hash'], $kind, $review['reason_codes'], $required,
                    $planReview['summary']['fully_allocated'], array_column($advice['alerts'], 'code')]),
                'kind' => $kind, 'review' => $review,
                'protected_token' => $token, 'expires_at' => $expiry ?? $now->addDay()->toISOString()];
        });
    }

    private function plain($resource): array
    {
        return json_decode(json_encode($resource->resolve(new Request), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }
}
