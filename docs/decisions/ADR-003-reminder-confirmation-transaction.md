# ADR-003 — Shared Reminder mutation core for confirmed AI actions

Status: accepted for Stage H3b, 2026-10-19.

## Context

Public ReminderService mutations deliberately reject ambient transactions. A fresh
owner-first transaction prevents an earlier REPEATABLE READ snapshot from hiding
concurrent target/dependency changes. H3a confirmation already owns an outermost
transaction for conversation/action locks, domain changes, durable receipt and
server history. Calling the public Reminder writer inside it would violate that
guard; independently committing a Reminder would violate receipt atomicity.

## Decision

Extract the existing authoritative create/update/cancel implementation into
`ReminderMutationService`. This internal component requires an existing transaction,
locks/reloads the owner and follows the existing work/session/Reminder order. It
never opens or commits a transaction. Its shared read-only prepareCreate and
prepareUpdate methods retain ReminderRules and ReminderTargetService validation,
including mode restrictions, relative resolution, future checks and eligibility.
Preparation does not execute a speculative writer or save and roll back a model.

Public ReminderService retains its outermost guard and owner-first transaction,
delegating to that core. Its reads, HTTP routes, Requests, Policy, Resource and
delivery behavior remain unchanged. H3b confirmation uses the same core after
locking conversation/action/owner, before any pre-owner consistent read. The
domain mutation, action receipt/state and history therefore share one connection
and one durable commit. Repeated locks in the core refer to the same already-held
owner/work/Reminder records; no new earlier domain lock is introduced.

Extend H3a's single pending-action table and confirmation endpoints, with seven
fixed domain adapters. Keep four Task schemas and seven H1 read definitions intact.
Two separate read adapters expose owned Subtasks and Reminders. One global proposal
counter includes all eleven mutation definitions, even invalid attempts.

The H3b migration also adds a stable single-column Conversation owner-reference
index, temporarily replacing and then restoring the existing user_id/updated_at
sorting index to move InnoDB's FK support to that stable index. Review reproduced
an implicit shared User lock during a real Conversation timestamp update when the
composite index supported the FK. That made history-only rejection block behind
an unrelated owner mutation, hidden by same-second no-op timestamp updates.
The additional index preserves rejection progress, FK enforcement, conversation
activity and list ordering without late owner locking or weaker tests.

## Consequences

The public guard remains a safety contract, not a boolean bypass flag. Only trusted
application code calls the internal core; it is not registered as a model-selected
Service/method or public endpoint. Future internal callers must acquire owner before
establishing a snapshot. Arbitrary SQL writers remain outside supported serialization.

No second connection, independent commit, duplicated Reminder rules, new workflow
engine, delivery action or inbox writer is introduced. The existing MySQL-clock
expiry, stale-state conflicts, receipt replay and successful-turn-only persistence
remain authoritative. Direct REST, AI confirmation and delivery concurrency are
verified with independent PHP processes and real MySQL row-lock waits.

The additive CHECK migration preserves H3a records. Downgrade refuses while H3b
history exists, so it cannot silently discard reviewed actions or receipts. Once
data is compatible, down restores the original four-type constraint and removes
the extra owner-reference index, retaining the original sorting index and FK.

See [H3b contracts and verification](../ai-subtask-reminder-actions.md) and
[ADR-002](ADR-002-ai-task-confirmation.md).
