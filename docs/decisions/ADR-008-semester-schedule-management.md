# ADR-008: Semester management, protected deletions and optimistic writes

Date: 2026-11-10
Status: Accepted for Stage 4, before implementation

## Decisions

Keep Stage 2 and legacy read contracts unchanged. New management reads return a
`schedule_token`: a SHA-256 canonical fingerprint of the complete owned semester
graph, timezone, academic context, catalogs, provenance and suppressed slots. Every
mutation of an existing schedule requires the token. Under the owner lock, reload
current authoritative data and reject a mismatch with HTTP 409 before any write.
Clients reload management state and decide again; there is no last-write-wins.
Creation has no existing token and checks period uniqueness under the owner lock.
A persisted monotonic `management_revision` also enters the fingerprint. Real
management/import changes increment it under that lock, so edit-then-revert within
one timestamp second cannot accidentally revive an old token/preview (ABA).
No-op rule/metadata patches and identical import commits do not increment it.

Extract the existing Stage 3 owned graph loading/validation into one concrete
SemesterScheduleStateService, shared by import and management. Locks remain owner
first, then import (where applicable), period/institution, catalogs, schedule,
rules, exceptions/children, suppressed slots and provenance in ID order. Shared
owner serialization prevents cooperating writers from acquiring competing graph
locks. Pure parity, pair conflicts and DST validation stay in the calculator.
All candidate writes validate the complete projected semester and snapshots.
Management reads expose owned definitions and their token even if an external
profile/calendar change invalidated their effective times; this permits a repair
mutation. Broken ownership/provenance dependencies still fail closed.

Add `is_manually_managed` to weekly rules. An actual manual edit permanently marks
the row, keeping its original import key, baseline and provenance immutable. Even
restoring the old values does not silently return it to import ownership. Row IDs
survive edits, including identity moves. No-op edits do not change ownership.

Add owned `semester_schedule_suppressed_slots`, unique by schedule/weekday/pair/
pattern, with composite owner FK and scalar CHECKs. Manual rule deletion suppresses
its current identity and its original imported identity when different. Moving an
imported rule also suppresses its original slot. Import-driven reconciled deletions
do not suppress slots. A suppressed incoming identity is a blocking preview error,
never a silent create. An explicit confirmed DELETE of the suppression releases
only that slot; it creates no rule, and requires revalidate/confirm of the import.
Manual creation into a suppressed slot requires releasing it first. Suppressions
and permanent manual flags enter import fingerprints; historical receipts remain
unchanged. There is no automatic legacy inference/backfill.

Date exceptions are full snapshots. PUT accepts either a complete lesson list
(with optional existing snapshot row IDs to preserve identity) or `copy_base` plus
rule-specific partial changes/removals and extra lessons, applied atomically.
An empty list is a cancelled date. Source provenance is server assigned; clients
cannot attach arbitrary rule IDs. Existing Saturday snapshots keep their kind and
copy marker when edited. Creating a working Saturday copies the source date's
*effective* state in the same Monday-first week, including any replacement or empty
cancellation. Both source and target must be within the semester. Later edits never
recopy snapshots. Deleting a rule detaches only nullable snapshot source links.

Schedule deletion requires explicit confirmation and a matching token, and is
allowed only when no rules, exceptions, suppressed slots or semester import
history for its period exist. Return a dependency error instead of a cascade or
history purge. Individual rule/exception/suppression DELETEs also require explicit
confirmation; replacements are explicit PUTs guarded by the current graph token.

## Consequences

One additive migration also adds the schedule revision; no old migration edits or retained database resets. Its
down refuses if protections exist. Existing Stage 3 reviews become stale after any
affected management mutation. Committed receipt replay remains immutable. Stage 2
occurrence identities/contracts, concrete Lessons, old Excel, frontend, planning,
AI, reminders and statistics remain independent. Catalog changes can conservatively
invalidate management tokens just as they invalidate import previews.
