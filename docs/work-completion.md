# Explicit work completion and session accounting

Work status is a student decision. Session status and actual minutes are independent.

Stage 1 adds authenticated SELECT-only `GET /api/tasks/{task}/completion-review`
and `GET /api/tasks/{task}/subtasks/{subtask}/completion-review`. Response fields:
`future_sessions_count`, `message`, `completion_token` (encrypted exact owned
session identities and intervals). The existing completion POST accepts that token.
A missing token with future sessions or any changed reviewed set returns 409 with
`completion_review`; no work, reminder or session write survives. Zero-session
legacy completion remains supported. A supplied zero-session token is rechecked.

Owner/work/session locks precede the operation clock. Only planned sessions with
`starts_at > now` are cancelled using model writes, preserving history and the
existing after-commit plan-attention observer. Existing work reminder completion
rules remain authoritative. Reopening never resurrects cancelled data. AI work
proposals show the server review and use its token only on explicit authenticated
pending-action confirmation. No model tool can grant consent.

Frontend task/subtask completion shares one review-before-write API adapter.
Each explicit action reads a fresh review; stale/unknown writes are never retried
automatically. The next attempt requires a fresh review and consent.

Stage 2 adds `GET /api/study-sessions/{id}/completion-review`: `can_complete_work`,
`work_type` and nullable `completion_review`. The complete POST adds optional
strict boolean `complete_work` (default false) and `completion_token`. It accepts
no client work ID. Required `actual_minutes` and lifecycle rules are unchanged.
A combined write runs inside the session transaction, uses the Task/Subtask
services and rolls back both transitions on stale consent or any write failure.
Completed/deleted work suppresses the checkbox; session-only completion is still
available under the existing rules. AI session proposals remain session-only;
combined completion is available through authenticated REST/UI, not model input.

Stage 3 preserves existing miss/cancel/transfer/split/deadline/reopen algorithms.
Read-only workload advice adds `review_estimated_effort` for open authoritative
Task work and `review_subtask_estimated_effort` for open authoritative Subtask
work whose completed credit covers its estimate. Reservations alone never trigger
these codes. Completed work and derived parent estimates are excluded. Frontend
advice links to the corresponding step editor via `?subtask_id=...`; all-steps
completion remains a positive exact count equality, independent of rounded percent.

## Verification record

All backend runs used `scripts/testing/phpunit-disposable-mysql.sh` (separate
fresh datadirs, database identities verified by the script). No working database,
schema, demo data or credentials were changed. Both repositories started clean on
`main`; all implementation commits remain on `main`.

- Stage 1: 163/163 Task/Subtask REST and AI lifecycle tests, plus 5/5 dedicated
  completion tests. Frontend new review adapter tests 3/3; type-check and build
  passed. Legacy frontend Subtask tests had stale button/date-bound expectations;
  those tests were subsequently updated to the existing checkbox and date bounds,
  preserving their lifecycle assertions.
- Stage 2: 308/308 session lifecycle/rule/AI and completion tests; final shared-clock
  recheck 8/8. Existing frontend targeted tests 98/98 and new checkbox/combined
  tests 3/3; type-check and build passed.
- Stage 3: 237/237 completion, workload advice, planning completion, attention,
  split replanning, exact progress, remaining effort and work selection tests.
  Additional AI zero-review/new-session conflict test: 1/1 (11 assertions).
  Final frontend targeted set: 169/169 across 8 files, including stale-set consent,
  no automatic POST retry, both work labels, default unchecked behavior, exact
  all-step equality, and direct step advice. Type-check/build passed.

Stage 1 changed backend Task/Subtask controllers and services, WorkCompletionService,
AI Task/Subtask proposal/confirmation presenters and adapters, API routes and their
lifecycle/consent tests. Frontend changed endpoints.ts, subtasks.ts, workCompletion.ts,
aiEffects.ts, and adapter tests/fixtures.

Stage 2 changed StudySessionController, CompleteStudySessionRequest,
StudySessionLifecycleService, TaskService/SubtaskService (shared operation clock),
routes and WorkCompletionTest. Frontend changed studySessions.ts,
StudySessionView.vue and session-work-completion.test.ts.

Stage 3 changed WorkloadAdviceService, completion/workload/AI regression tests and
current architecture/domain/business-rule notes. Frontend changed workload
recommendations, WorkloadAdviceView, useSubtasks, TasksView, workCompletion.ts
(neutral declined-consent handling), and relevant tests.

Limits: this is targeted verification, not a full repository suite or live-browser
acceptance run. AI session proposals intentionally expose no combined-completion
flag. A stale AI work proposal must be proposed/reviewed again; re-confirming its
old persisted review cannot authorize a different session set. Queue workers and
the existing Scheduler remain necessary for eventual plan attention analysis.

## Manual acceptance

1. Open an elapsed planned session. Enter actual minutes and leave the checkbox
   unchecked. Complete it: only the session changes; work remains open.
2. Open an elapsed Subtask session that has another future planned session. Check
   “Також позначити підзавдання виконаним”, enter actual minutes and accept the
   server count. Only that step completes; its future session/reminder cancel;
   the parent and other steps retain their statuses.
3. Complete a Task with its own and child future sessions. Review the server count
   before confirmation. Future plans cancel; elapsed unresolved sessions remain
   available for an explicit outcome; completed history/actual minutes remain.
4. Reopen the Task/step. Cancelled sessions and reminders remain cancelled and
   visible in history. Review a new planning preview to allocate more study time;
   reopening itself creates no sessions.
