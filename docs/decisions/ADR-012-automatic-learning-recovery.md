# ADR-012: Automatic recovery proposes; authenticated students apply

Status: accepted for implementation by the current task's explicit product decision. Runtime verification is deferred.

## Context

Existing recovery and adaptive replanning already provide deterministic preview tokens and transactional writers. AI pending actions require a conversation and cannot correctly represent server-generated reviews. Unknown elapsed-session outcomes must remain unknown. Academic changes can invalidate future study allocation without authorizing automatic relocation.

## Decision

Use `RecoverySuggestion` and one per-owner scan-state row, with a forward migration, for a narrow owner review lifecycle. Reuse existing planning/replanning tokens, validation, Resources and writers. Store protected tokens encrypted; never return them. One pending owner slot and immutable applied receipts provide idempotent decisions. Use owner-first locking, source fingerprints, nested transaction savepoints and existing independent batch validation before writes. A failed write rolls back its business changes. No automatic retry of a mutation.

A bounded scheduled command detects current conditions, creates review metadata only, and rotates owners by scan time. Preserve unknown outcomes and actual durations. A separate authenticated feed supplies frontend review data; do not couple suggestion creation to reminder delivery. Calendar authority remains the existing shared effective academic occupancy, including semester rules/exceptions and eligible legacy sources.

Reviewable allocation needs an earliest future start: add an optional server-only cutoff to the existing allocator entry points and encrypted token parameters. Existing callers and old four-parameter tokens retain their behavior. A 900-second automatic lead is policy, not a second scheduling algorithm. Source and qualitative-need fingerprints avoid repeated unchanged reviews; explicit idempotent refresh creates a new immutable proposal identity. Replanning precedes additional-work allocation. Separate partial and unplaced cancellation consent is mandatory where applicable.

## Consequences

No generic workflow engine, new allocator, conversation fabrication, unattended session mutation, new productivity formula or automatic notification channel is introduced. Complete input caps can exclude large accounts from automatic analysis; existing scoped manual workflows remain available. Conservative stale checks can require rereview after harmless changes. Pending metadata is resolved lazily by scanner/decisions; GETs expose effective status without saving. Scheduler operation, database concurrency, external delivery, live LLM selection and frontend integration require dedicated verification.

See [API, limits and verification](../automatic-learning-recovery.md), [implementation scope](../learning-recovery-implementation.md), [source snapshot service](../../app/Services/Planning/RecoverySourceStateService.php) and [review service](../../app/Services/Planning/RecoverySuggestionService.php).
