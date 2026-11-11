# ADR-009: Automatic semester catalogs and multi-teacher lessons

Date: 2026-11-11
Status: Accepted by the automatic semester import task

This extends ADR-006/007/008. It supersedes ADR-007's manual catalog prerequisite
and client-selected import parity; manual schedule management retains its existing
metadata controls. The approved task explicitly requires these changes.

Semester uploads accept only academic_period_id and schedule_file. The server
uses Monday of the start date's calendar week, always numerator first. The anchor
can precede the period; the existing calculator still bounds virtual occurrences
to period dates. Revalidation recalculates from the current owned period.

Name matching is deterministic against locked owned catalogs, normalizing case,
whitespace, apostrophes and spacing around periods. Ambiguity is a validation
error. Valid missing names become read-only prepared identities in the preview,
then real catalog records only after commit's existing stale checks. Owner locking
serializes cooperating catalog/import writers. Catalog creation, pivots, schedule
reconciliation and durable receipt share the existing transaction. No additional
confirmation step is introduced. Repeated imports reuse identities.

Three additive pivots link Subjects, weekly rules and snapshot lessons to Teachers.
Each unique pair includes user_id with composite FKs to both owned parents.
Teacher deletion is restricted while referenced, protecting frozen snapshots.
Subject teaching associations grow without detaching existing catalog links.
Scalar teacher_id remains a compatibility view; complete lists are canonical,
unique and sorted by ID. Manual writers, snapshots and working Saturdays share
the semester teacher-link writer. Legacy Lesson relationships remain separate.

The migration backfills existing scalar links without rewriting any historical
import baseline or receipt. The planner normalizes old baselines to zero/one
teacher and new baselines to complete lists; untouched historical imports remain
managed. Fingerprints include full teacher lists and subject teaching links.
The existing preview token, 409 refresh, manual/suppressed-slot protections,
stable recurring identities and independent snapshots remain authoritative.

No tests are written or run for this task, per explicit user instruction.
