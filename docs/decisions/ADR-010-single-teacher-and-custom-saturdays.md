# ADR-010: Single semester teachers and custom working Saturdays

Date: 2026-11-11
Status: Accepted by the single-teacher/custom-Saturday task

This supersedes ADR-009's multi-teacher requirement and extends ADR-008's Saturday
copying options. Automatic catalogs, import parity derivation and legacy dated
Lesson/import separation remain unchanged.

Use existing scalar lesson teacher_id as canonical. All new/edited/copied nonempty
semester lessons require exactly one owned teacher. Keep the three existing pivots
and all import baseline/receipt formats. The catalog subject_teacher link is assigned
once, checked alongside scalar references in all of the owner's semesters, and
cannot be silently repointed by imports or manual lesson writers. No new Subject
column is needed. Existing Subject/Teacher Eloquent relationships keep historical
links and allow one Teacher to teach several Subjects.

Multiple historical teacher identities, or a lesson pivot without a compatible
scalar, fail closed with a manual-repair validation error. No automatic data cleanup
or arbitrary selection occurs. Existing teacherless rows may be read but require a
teacher when edited/copied. A scalar edit mirrors its validated single teacher into
compatibility pivots; it never cleans up a historical multi-teacher row. Import
previews retain source-specific cardinality and assignment errors, and writes still
begin only after owner-locked stale revalidation.

Custom working Saturdays select owned base weekly rules by weekday plus explicit
numerator/denominator (including all-pattern rules), then copy a nonempty chosen
subset into a frozen date snapshot. The explicit parity is independent of calendar
parity; authoritative fields never come from the client. Existing management reads
supply source definitions and the graph token, so no duplicate preview endpoint is
added. The complete projected semester and target local endpoints are validated
before writes, in the existing owner-locked transaction.

When both new fields are absent, retain same-week effective-day copying, including
replacements and cancellation. A single additive nullable source_week_parity column
preserves old snapshots with null values. New copies retain explicit source parity
(or calendar source parity in compatibility mode). Snapshot edits keep source
metadata unchanged. The forward migration preserves populated-data rollback guards.
