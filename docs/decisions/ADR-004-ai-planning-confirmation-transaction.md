# ADR-004 — Planning execution inside durable AI confirmation

Status: accepted for H3c, 2026-10-19.

## Context

Stage C/E2 confirmations and Stage D's mutation context own fresh outermost
transactions. H3 confirmation must commit business effects, its action receipt and
conversation outcome together. Nesting those public APIs violates their safety
guards; committing separately loses durable at-most-once execution. Existing
planning tokens already protect owner, purpose, version, lifetime and complete
review comparison. Those contracts remain authoritative.

## Decision

Keep each public transaction guard. Extract the existing body into
`StudyPlanPersistenceService::confirmWithinTransaction()`,
`StudyReplanningPersistenceService::confirmWithinTransaction()` and
`StudySessionMutationContextService::runWithinTransaction()`. The rescheduling
Service shares one writer closure between public `reschedule()` and trusted
`rescheduleWithinTransaction()`. Internal entry points require a transaction,
reload/lock owner, perform the same checks and never commit independently.
Only trusted application callers may use them after acquiring owner before a
consistent-read snapshot. H3 acquires conversation/action/owner in that order.

Use three fixed proposal adapters and the existing pending-action lifecycle, not a
second confirmation framework. One nullable LONGTEXT `protected_payload` uses
Laravel's encrypted-array cast and Hidden attribute. It contains only the
server-issued domain token and its validated absolute expiry, bounded to 262144
serialized bytes before encryption. Neither provider arguments/results, public
previews, transcripts, replay results nor receipts contain tokens. Bind decoded
token parameters to the immutable stored action arguments.

At successful turn finalization, decode again and cap expires_at to the lesser of
MySQL UTC_TIMESTAMP(6) + 15 minutes and the domain token's whole-second UTC expiry.
Reject a token already expired according to either the domain's UTC application
clock or MySQL, including expiry during finalization. Confirmation checks the
MySQL action deadline and all existing domain token checks after waits and writes.
No token lifetime is renewed. Clock skew can shorten eligibility, never authorize
an expired token by extending its action deadline.

Only authenticated confirmation bodies may contain genuine boolean consent:
accept_partial for plan creation; accept_partial and accept_unplaced_cancellation
for adaptive replanning. Rejection and all other action types keep empty bodies.
Domain acceptanceError rules determine which distinct flags are required. Model
arguments never select consent, owner, tokens or replacement batches.

Manual preview shares source discovery, chain validation, transition, future and
transfer-duration preflight with the actual Service. It is explicitly preliminary:
remaining effort and C10 feasibility require confirmation-time source retirement.
Fingerprint sorted raw parent/child and every connected session record, not only
updated_at. Plan/E2 actions use their existing full token-review comparisons.

H3 confirmation owns one outer transaction. A planning attempt uses a same-connection
savepoint: expected stale/conflict/token/consent failures roll domain changes back
before recording a safe version-2 failed-attempt event under the retained
conversation/action/owner locks. The pending action keeps its existing status and
null receipt. Throw the safe HTTP error after that event commits. This prevents a
new AI turn from interleaving before the authoritative failure outcome. Unexpected
write/history failures roll back the outer transaction. Success commits domain
changes, executed state, receipt and history together. Receipt replay invokes no
writer or provider. No independent connection or provider I/O enters a transaction.

## Lock and compatibility consequences

Plan creation keeps owner serialization followed by existing nonlocking Stage A/B/C9
reads and session insert/FK locks in proposal order. It adds no cross-table lock
scheme. E2 retains ascending owned Task locks, ascending Subtasks, connected session
components by ID, then scheduled Reminder locks by source/Reminder ID. Manual
rescheduling retains owner, parent, child, ascending connected session records,
then scheduled source Reminders. Cooperating availability, preference, schedule,
work and delivery writers serialize through owner and never acquire AI locks.
No consistent snapshot precedes the owner wait. H3b's stable Conversation owner
reference index remains unchanged.

Public REST responses, conservative direct REST replay conflicts, planning
algorithms, constraints, source/successor history and Reminder semantics remain
unchanged. H3 adds durable receipt replay only to AI actions. Empty/nonactionable
reviews create no action. Full public reviews are bounded without truncation;
oversized reviews fail safely and require narrower scope.

The additive migration expands the type CHECK to fourteen, preserves legacy data
and refuses downgrade while H3c action history or any protected payload remains.
Compatible down removes only the private field and restores eleven allowed types.
The four action states and existing migrations remain unchanged.

See [the H3c implementation and verification](../ai-study-planning-actions.md).
