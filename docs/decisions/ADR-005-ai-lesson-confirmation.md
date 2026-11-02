# ADR-005 — Lesson proposals in the existing AI confirmation transaction

Accepted by the operator, 2026-11-02, for Stage 11 Backend first.

Add `propose_lesson_create`, `propose_lesson_update`, `propose_lesson_cancel` and
`propose_lesson_replacement` to the allowlist. Each prepares a review and never
writes business records. The existing pending-action table, successful-turn
finalization, explicit authenticated decisions, lease exclusion, MySQL deadline,
atomic receipt/history and replay rules remain authoritative. The one-proposal
limit includes all 18 mutation definitions globally.

Extract read-only preflight methods from the existing `LessonService`; use them
for preparation and again for confirmation. Keep all writes in its existing four
writer methods. Share Form Request field rules with the AI argument validator.
Use authenticated owner scopes plus LessonPolicy, never caller-selected owners.
Do not introduce speculative writes, a second confirmation protocol, generic
reflection dispatch or independent commits.

Fingerprint raw Lesson/original/replacement history and all reviewed association
rows, then compare the regenerated material preview at confirmation. Owner locking
precedes the first consistent read. Domain writes and receipt/history share H3's
outer transaction. Missing/changed records are stale; new conflicts fail current
validation. Model write cancellation and incomplete replacement transitions must
not produce successful receipts. Expected failures roll back and leave the pending
action unexecuted, consistent with existing Task/management actions.

Add one forward-only migration expanding `ai_actions_valid_type` from 14 to 18.
No columns or old migrations change. Down refuses; future corrections use a new
migration. Deploy schema before new proposal code; application downgrade after
Lesson history requires compatibility work. Test schema lifecycle rebuilds the
explicitly guarded disposable database instead of invoking production downgrades.

Frontend work is deferred by the operator's Backend-first instruction. Its current
unknown-type confirmation block is retained. The integration must support four
readable reviews and refresh schedule only after successful confirmation; it must
not implement scheduling rules. Paid provider behavior is not part of this approval.

See [implementation](../ai-lesson-actions.md), [REST handoff](../stage11/frontend-rest-contract.md)
and [results](../stage11/implementation-report.md).
