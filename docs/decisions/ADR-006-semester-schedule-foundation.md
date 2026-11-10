# ADR-006: Semester rules and frozen date snapshots

Date: 2026-11-10

Status: Accepted for the additive foundation only

## Context

Concrete UTC `lessons` support the current schedule, import, planner and AI tools.
The supplied weekly workbook instead describes a semester template with alternating
Tuesday pairs and no calendar anchor. Expanding it months ahead makes template
edits and isolated date changes difficult. Existing periods can lack an institution;
existing legacy FKs enforce existence rather than owner equality.

## Decision

Add SemesterSchedule, WeeklyLessonRule, ScheduleDateException and its independent
lesson rows without changing any current API or consumer. Store a Monday parity
anchor and its explicit numerator/denominator value. Use the period's inclusive
boundaries and owned institution; preserve legacy nullable institution behavior.
Read a complete effective day deterministically through owned persisted sources.

A date snapshot replaces the complete day, including an empty cancellation.
Working Saturday is one dated snapshot with a Monday–Friday copy marker; reads
never dynamically copy another date. Snapshot fields do not inherit later rule
edits. Rule provenance is optional and does not determine effective content.

Reject duplicates, effective pair collisions and overlapping half-open intervals
across both parity weeks. Reject nonexistent/ambiguous local endpoints explicitly;
unique endpoints spanning a DST transition retain their actual elapsed duration.
Use persisted User timezone and stable virtual occurrence identities. Factor pure
calculations only to make the same rules testable without Laravel/MySQL and reusable
for future write preflight, following ADR-001 without repositories or DTO layers.

Use composite FKs for new graph ownership and same-schedule provenance, supported
by additive candidate keys on existing parents. Keep cross-row calendar bounds,
mandatory owned institution and import-period compatibility as Service invariants.
Restrict catalog/provenance removal until explicitly detached or safely handled.

## Consequences

The existing application remains on Lessons until a separate integration stage.
No legacy migration, materialization, writer or new confirmation workflow is
introduced. Future writers must validate complete candidates, dates, institutions
and provenance under owner-first transactions; SQL uniqueness alone cannot enforce
arbitrary time overlap. Later catalog lifecycle integration must handle the new
restricted dependencies with appropriate API errors.

See [the full foundation contract](../semester-schedule-foundation.md) for schema,
output identity, DST policy, enforcement limits, rollout and regression coverage.
