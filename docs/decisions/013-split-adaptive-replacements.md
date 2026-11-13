# ADR-013: Multiple successors for adaptive replanning

Status: accepted by the product request, 2026-11-13.

A future planned source may be replaced by several planned sessions. Reuse
StudySessionAllocationService without singlePlacement. Cap aggregate replacement
seconds by source duration and authoritative unallocated effort. Preserve every
immediate edge using rescheduled_from_session_id; ancestors are immutable.

Migration 2026_11_13_000001 replaces the unique predecessor index with a normal
index, retaining FK ON DELETE SET NULL and all existing records. Rollback refuses
when a split exists; it never deletes data to restore uniqueness.

Preview rows contain ordered replacements[]. Singular replacement remains an alias
only for a single successor, null otherwise. Version 2 tokens reject previous
previews. Every split requires accept_split_replacements=true separately from
partial-result and unplaced-cancellation consent, across REST, recovery and AI.
This prevents old clients from confirming unseen split sessions.

Owner-first locks, full connected-history validation, fresh preview comparison,
independent full-batch constraints before/after retirement, token/start-time
rechecks and reminder cancellation remain transactional. Completed/missed records
are never reclassified. Reminder history is preserved; reminders are not cloned.
