# ADR-007: Confirmed recurring semester imports

Date: 2026-11-10

Status: Accepted for Stage 3

## Decision before implementation

Use a separate persisted SemesterScheduleImport workflow: upload, GET review,
revalidate and explicitly confirmed commit. Store the parsed weekly template, user
selected anchor/parity, current review and a random revision token. Commit requires
`confirmed: true` and that token. Under the common owner lock, rebuild the review
and fingerprint the authoritative period/institution, timezone, catalogs, schedule,
rules and complete date-exception graph. Any difference refreshes the preview and
returns 409, with a new token and no schedule write. GET never revalidates silently.
Committed retries with the same token return the original receipt without mutation.

Add one table and nullable provenance/key/baseline columns on WeeklyLessonRule.
The old `source_import_batch_id` targets legacy concrete Lesson import batches and
cannot safely identify this independent workflow; it remains untouched/null for
new imports. Existing data receives no inferred provenance or conversion.

Identify an imported rule by ISO weekday + pair_number + week_pattern within its
schedule. Subject, teacher, room and local times may change while identity/row ID
survives. Reuse ScheduleWorkbookParser and the existing exact owned name resolver;
missing or ambiguous catalogs produce errors, never implicit catalog creation.
Pair labels map explicitly to numbers 1–4. Reuse EffectiveScheduleCalculator for
both-parity conflicts and timezone/date validity, without materializing Lessons.

Reconcile only unchanged rules managed by this workflow, comparing each current
rule with its last imported baseline. Preserve manual rules and manually edited
imports. Identical protected entries may satisfy a source row without adoption;
differing protected targets or time conflicts block the complete commit. No force
replacement option is introduced. Removed unchanged managed rules are explicitly
listed as deletions in the review; all other user rules remain. Metadata changes
(group/anchor/parity) are shown before/after and covered by explicit confirmation.

Snapshots remain independent and are never recopied or deleted. Before deleting a
reviewed managed rule, detach only its owned snapshots' nullable source references
inside the same transaction, preserving every saved lesson field and ID. Respect
FKs; do not disable them in application code. Show affected snapshot references
in the preview. Frozen snapshots do not inherit new group/parity/template changes.

## Transaction and compatibility boundary

Parse the temporary upload before locks. Transactions lock owner, import, owned
period/institution, catalogs, schedule, rules, exceptions/children and provenance
in deterministic order. Cooperating academic/import writers share the owner lock.
Revalidate current locked data, not cached IDs or the original workbook. Deletes,
detachment, metadata, inserts/updates and durable receipt commit or roll back
atomically. The review is a conservative snapshot: even unrelated owned catalog
changes invalidate confirmation. No timeout or paid AI/provider dependency exists.

Preserve all old migrations, legacy imports/APIs and Stage 2 JSON contracts. No
frontend, AI Tool, planning, FreeTimeService, Reminder or statistics change. A new
forward schema migration is required; no retained database reset or automatic
legacy data migration is permitted. Populated import-history downgrade refuses.
