# Academic Context API

Stage 04 implements authenticated CRUD for personal Education Institutions,
Academic Periods, Subjects, and Teachers. Every endpoint uses `auth:sanctum` and
Sanctum's existing first-party SPA session/cookie flow. See [authentication](auth-profile.md)
for credentialed requests and CSRF setup. No API version prefix is used.

## Routes and status codes

| Method | Route | Success |
| --- | --- | --- |
| GET | `/api/education-institutions` | 200 |
| POST | `/api/education-institutions` | 201 |
| GET | `/api/education-institutions/{educationInstitution}` | 200 |
| PATCH | `/api/education-institutions/{educationInstitution}` | 200 |
| DELETE | `/api/education-institutions/{educationInstitution}` | 204 |
| GET | `/api/academic-periods` | 200 |
| POST | `/api/academic-periods` | 201 |
| GET | `/api/academic-periods/{academicPeriod}` | 200 |
| PATCH | `/api/academic-periods/{academicPeriod}` | 200 |
| DELETE | `/api/academic-periods/{academicPeriod}` | 204 |
| GET | `/api/subjects` | 200 |
| POST | `/api/subjects` | 201 |
| GET | `/api/subjects/{subject}` | 200 |
| PATCH | `/api/subjects/{subject}` | 200 |
| DELETE | `/api/subjects/{subject}` | 204 |
| GET | `/api/teachers` | 200 |
| POST | `/api/teachers` | 201 |
| GET | `/api/teachers/{teacher}` | 200 |
| PATCH | `/api/teachers/{teacher}` | 200 |
| DELETE | `/api/teachers/{teacher}` | 204 |

Unauthenticated requests return 401. Missing or foreign concrete resources return
404, including foreign PATCH requests with invalid input. Input and deterministic
business-rule failures return Laravel's standard 422 `message` / `errors` shape.
DELETE returns an empty 204 when allowed. There are no PUT, bulk, search, filter,
or pagination contracts in this vertical.

## Ownership and public Resources

Collections originate from the authenticated user's relationships. Creation and
mutations use trusted server authentication context and validated field
allowlists. Body/query `user_id` and supplied primary keys cannot choose or
reassign ownership. Policies authorize concrete-resource view/update/delete;
PATCH authorization occurs before payload validation. Services re-read mutation
targets through the trusted user's relationships.

API Resources use the normal Laravel `data` envelope. They expose no `user_id`,
timestamps, or nested institutions/lessons/tasks. Example single-resource shapes:

```json
{"data":{"id":1,"name":"University"}}
```

```json
{"data":{"id":2,"education_institution_id":1,"name":"Semester 1","starts_on":"2026-09-01","ends_on":"2026-12-31"}}
```

```json
{"data":{"id":3,"education_institution_id":1,"name":"Computer Architecture","code":"CA-101","color":"#a1B2c3"}}
```

```json
{"data":{"id":4,"education_institution_id":1,"name":"Teacher Name"}}
```

Collections use `{"data":[...]}` (or `{"data":[]}` when empty). Institutions,
Subjects, and Teachers are ordered by name then ID; Periods by start date then ID.
Teacher names may repeat, so the ID tie-breaker is significant.

## Create and partial PATCH fields

| Resource | Required on POST | Optional/nullable on POST |
| --- | --- | --- |
| Institution | `name` | None |
| Period | `name`, `starts_on`, `ends_on` | `education_institution_id` |
| Subject | `name` | `education_institution_id`, `code`, `color` |
| Teacher | `name` | `education_institution_id` |

PATCH permits any subset of that resource's fields. Supplied names must be
nonempty strings of at most 255 characters. Unknown fields are ignored. Omitted
values remain unchanged, explicit null clears nullable fields, and an empty PATCH
is a no-op for a valid record. Omitted optional fields on POST become null.

Institution IDs must be null or strict positive JSON integers. A non-null ID must
resolve through the authenticated user's institutions. Foreign and nonexistent
IDs produce the same controlled 422 error under `education_institution_id`.
`AcademicPeriodService`, `SubjectService`, and `TeacherService` enforce this rule
on the complete candidate state, so future validated tool adapters can reuse it.

Institution and Subject names are unique per user according to the existing
MySQL comparison/uniqueness behavior. Subject uniqueness is not per institution;
Subject codes are not unique. Period uniqueness is exactly `(user_id, name,
starts_on)`: the same name with a different start date is allowed. Unchanged
current names/tuples are accepted on PATCH. Teacher names are deliberately not
unique; the same user can create/update multiple Teachers with identical names.

Period dates use strict `Y-m-d` input and serialization. The Service merges PATCH
with the latest persisted state and requires `starts_on <= ends_on`; equal dates
are valid. Invalid complete or partial ranges return 422 before a MySQL CHECK
failure. Calendar dates are not converted to UTC timestamps.

Subject codes may be null or strings up to 50 characters. Empty Subject code is
allowed and is preserved as an empty string; no additional normalization or
uniqueness is introduced. Color may be null or exactly `#RRGGBB` with hexadecimal
digits. Uppercase, lowercase, and mixed casing are preserved. Empty color, missing
`#`, short/long values, spaces within the value, or non-hexadecimal digits return
422 before persistence. The Subject-only empty-string middleware exception
preserves the distinction between empty color and explicit null; other endpoints
retain Laravel's normal empty-string-to-null behavior.

## Deletion and history

Normal deletion of these four resources is hard deletion, using the existing FK
rules without schema changes:

| Resource | Allowed deletion and preservation | Controlled 422 protection |
| --- | --- | --- |
| Institution | Owned Periods/Subjects/Teachers survive with institution FK set null | Any referencing Period/Subject/Teacher belonging to another user |
| Period | Deletes when no schedule/import history exists | Any import batch or Lesson reference, including soft-deleted or foreign malformed history |
| Subject | Owned Tasks survive with subject FK set null, including soft-deleted Tasks | Any Lesson reference (active/cancelled/replaced/soft-deleted), or any foreign Task reference, including soft-deleted Tasks |
| Teacher | Owned Lessons survive with teacher FK set null, including soft-deleted Lessons | Any foreign Lesson reference, including soft-deleted Lessons |

Services serialize destructive mutations with user and target-row locks inside
transactions, use locking reads for dependent checks, and reject before FK actions
can alter another user's rows. Errors are generic and disclose no foreign owner
identity or dependent resource IDs. Valid same-owner SET NULL behavior is retained.
No Lesson or Task is manually deleted by these operations.

## Malformed legacy institution associations

Simple FKs intentionally enforce existence, not cross-row ownership. Factories or
legacy/direct database writes can physically create an owned Period/Subject/Teacher
that references another account's institution. Public Resources serialize such an
institution reference as null; a valid same-owner reference returns its actual ID.
Collection endpoints eagerly load ownership-compatible institutions to avoid N+1
queries. GET does not write, detach, or repair the underlying association.

An unrelated PATCH while the invalid association remains returns controlled 422
and leaves data unchanged. The owner must explicitly submit
`education_institution_id: null` or an owned institution ID to repair it. Normal
updates then work. A masked null in a GET response does not itself mean the stored
malformed FK was cleared.

The institution/teacher/subject destructive guards also account for malformed
children belonging to other users. Account-wide administrative purge remains a
separate workflow and is not implemented by this API.

## Implementation boundary and tests

Controllers stay thin; Form Requests own input shape and mutation authorization,
Policies own concrete-resource access, and Academic Services own reusable
relationship/lifecycle rules. Institution create/update remains simple HTTP
orchestration; `EducationInstitutionService` exists only for guarded deletion.
No generic repository or CRUD abstraction is introduced.

`AcademicContextSecurityTest` covers malformed relationships, unchanged rows on
rejection, repair, disclosure masking, scoped eager loading, direct authorization,
and preservation of valid FK behavior. Existing API and MySQL persistence suites
retain their coverage, including the physical simple-FK ownership limitation.

Lesson/schedule APIs, import parsing/preview/commit, Task/Subtask application
workflows, free-time calculation, automatic planning/rescheduling, reminders,
statistics/reports, AI orchestration/tools, and frontend work remain unimplemented.
Academic Context does not introduce product planning defaults.
