# Physical Database Schema

Status: **Migration Groups 1–9 implemented; Stage 02 persistence complete**\
Scope: Laravel backend + MySQL + Eloquent\
Important: this is the canonical repository adaptation of the approved `stage-02-database-schema-spec.md`. All nine approved persistence groups are implemented alongside the Laravel/Sanctum scaffold. The MySQL enforcement decisions below preserve the approved tables, columns, keys, and delete actions.

**Implemented now (Migration Groups 1–9):**

- `users.timezone`;
- `planning_preferences` and `study_availability_windows`;
- user-owned `education_institutions`, explicit `academic_periods`, `subjects`, and `teachers`;
- `schedule_import_batches` and `schedule_import_rows` for persisted import staging/history, with `ScheduleImportBatchStatus` and `ScheduleImportRowStatus` PHP string-backed enums over bounded VARCHAR columns;
- `PlanningPreference` and `StudyAvailabilityWindow` models, their `belongsTo(User::class)` relationships, and `User::planningPreference()` / `User::studyAvailabilityWindows()`;
- `EducationInstitution`, `AcademicPeriod`, `Subject`, and `Teacher` models with `belongsTo(User::class)`; `AcademicPeriod`, `Subject`, and `Teacher` also have optional `belongsTo(EducationInstitution::class)` relationships; academic-period dates use Laravel date casts;
- `User::educationInstitutions()`, `User::academicPeriods()`, `User::subjects()`, and `User::teachers()`; `EducationInstitution::academicPeriods()`, `EducationInstitution::subjects()`, and `EducationInstitution::teachers()` are also implemented as `hasMany` relationships;
- `ScheduleImportBatch::user()`, `ScheduleImportBatch::academicPeriod()`, and `ScheduleImportBatch::rows()`; `ScheduleImportRow::scheduleImportBatch()`; `User::scheduleImportBatches()` and `AcademicPeriod::scheduleImportBatches()`; batch status/datetime/counter casts and row status/JSON-array casts;
- `lessons`, the `Lesson` model with `SoftDeletes`, `LessonStatus` / `LessonType` enum casts, UTC datetime fields, and relationships to its user, period, subject, teacher, import batch, original, and replacement; `User`, `AcademicPeriod`, `Subject`, `Teacher`, and `ScheduleImportBatch` each expose `lessons()`;
- `tasks` and `subtasks`, with `TaskStatus`, integer-backed `TaskPriority`, completion/estimate/position constraints, soft deletion, and parent/subject/session/reminder relationships;
- `study_sessions`, with `StudySessionStatus`, completion/actual-effort constraints, nullable subtask and predecessor relationships, one direct successor per predecessor, and lifecycle history without soft deletion;
- `reminders`, with `ReminderStatus`, nullable `ReminderAnchor`, signed offsets, explicit target relationships, and the four DB-safe checks below;
- `ai_conversations` and `ai_messages`, with `AiMessageRole`, JSON-array tool-call metadata, and conversation/message cascades; messages automatically write `created_at` and have no `updated_at`;
- the approved primary/foreign keys, indexes, uniqueness constraints, owner cascade deletes, optional `SET NULL` deletes, and thirty-six enforced MySQL CHECK constraints across fifteen domain tables; historical Lesson/import `RESTRICT` references remain unchanged. Unsupported self-reference and reminder target checks are assigned explicitly to future deterministic Services;
- factories and focused MySQL-backed `PlanningPersistenceTest`, `AcademicContextPersistenceTest`, `ScheduleImportPersistenceTest`, `LessonPersistenceTest`, `TaskPersistenceTest`, `StudySessionPersistenceTest`, `ReminderPersistenceTest`, and `AiPersistenceTest`. Factory defaults are valid and ownership-consistent; the StudySession factory derives its owner from its Task. Import/AI JSON fixtures do not define future processing protocols. `PlanningMigrationTest` dynamically rolls back and reapplies domain migrations while preserving the four scaffold migrations and existing users, explicitly covering all Groups 1–9 without a fixed rollback count.

**Implemented Stage 03 application behavior:** Authentication + User Profile uses Sanctum first-party SPA session/cookie authentication. Planning Preferences exposes side-effect-free singleton reads and complete nullable replacement/upsert through `PlanningPreferenceService`, which enforces session/workload bound ordering. Study Availability exposes user-scoped CRUD through `StudyAvailabilityService`, which enforces local interval ordering and deterministic same-user/day overlap rejection. The auto-discovered `StudyAvailabilityWindowPolicy` hides cross-user mutations with 404. See [the auth/profile contract](auth-profile.md) and [planning settings contract](planning-settings.md).

**Implemented Stage 04 application behavior:** the four Academic Context resources have authenticated CRUD, public Resources and ownership Policies. Academic Services enforce optional same-owner institutions, complete-candidate validation, uniqueness/date rules, and history-protected deletion. Institution/Subject/Teacher destructive guards prevent SET NULL side effects on malformed foreign children, including soft-deleted Tasks/Lessons. Legacy foreign institution IDs are masked on reads without repairing persistence; explicit owner repair is required before normal updates. See [academic-context.md](academic-context.md).

**Still unimplemented:** lesson/schedule APIs, actual free-time calculation, automatic study planning, task/subtask application workflows, import parsing/preview/commit, reminder application/delivery logic, statistics, and AI orchestration/tools. Persistence of those entities does not implement their workflows.

Groups 1–9 remain the completed Stage 02 persistence scope. Stage 03 materializes preference rows only on authenticated PUT, preserves side-effect-free GET, and implements trusted-user ownership and availability-overlap validation for the current APIs. Stage 04 enforces Academic Context institution compatibility and its destructive-operation ownership boundary. Ownership compatibility for Lesson/Task/import/Reminder associations, task deletion cleanup, rescheduling, and reminder target validation/recalculation remain unimplemented; product defaults remain undecided. Import staging exists, but Excel parsing, uploads/file storage, preview endpoints, validation/commit Services, duplicate resolution, fingerprint generation, and import-driven Lesson creation remain unimplemented. Simple foreign keys enforce existence, not cross-row matching ownership or task/subtask compatibility. Self-replacement/self-rescheduling prohibitions and lifecycle transitions await deterministic Services.

The companion [DBML source](database-schema.dbml) preserves the supplied `student-ai-planner.dbml` diagram. It uses numeric shorthand and omits SQL CHECK expressions; the MySQL types and constraints below remain authoritative. See [the conceptual domain model](domain-model.md), [business rules](business-rules.md), and [the earlier draft review](database-review-notes.md) for context. Known implementation issues are recorded in section 8 without changing approved tables, columns, keys, or delete actions.

## 1. Scope and conventions

The schema keeps Laravel's existing framework tables unchanged and adds the domain tables required by the approved Stage 02 design.

Framework/system tables that already exist or are provided by Laravel/Sanctum are outside the domain diagram:

- `password_reset_tokens`
- `sessions`
- `cache`
- `cache_locks`
- `jobs`
- `job_batches`
- `failed_jobs`
- `personal_access_tokens`

General conventions:

- surrogate `id` primary keys: `BIGINT UNSIGNED AUTO_INCREMENT`; `planning_preferences.user_id` is instead the non-incrementing primary/foreign key;
- foreign keys: `BIGINT UNSIGNED`;
- timestamps: Laravel `created_at` / `updated_at`;
- soft deletion only where explicitly listed;
- concrete domain instants stored as UTC `DATETIME`; existing framework fields and Laravel audit/soft-delete timestamps retain the `TIMESTAMP` types explicitly listed below;
- recurring local availability stored as `TIME`;
- user timezone stored as IANA timezone name in `users.timezone`;
- controlled lifecycle states stored as `VARCHAR` and cast to PHP backed enums;
- task priority stored as `TINYINT UNSIGNED`;
- PHP backed enums with scalar MySQL columns are the enum strategy; MySQL `ENUM` is not used;
- free slots, overdue flags, progress percentages, workload totals and planning conflicts are derived, not persisted.

## 2. Enum/value baseline

### LessonType
Stored as `VARCHAR(32)`:

- `lecture`
- `practical`
- `laboratory`
- `seminar`
- `consultation`
- `exam`
- `other`

### LessonStatus
Stored as `VARCHAR(20)`:

- `active`
- `cancelled`
- `replaced`

### TaskStatus (shared by Task and Subtask)
Stored as `VARCHAR(20)`:

- `pending`
- `completed`

`overdue` is derived from `deadline_at`, status and current time.

### TaskPriority
Stored as `TINYINT UNSIGNED`:

- `1` = LOW
- `2` = NORMAL
- `3` = HIGH

### StudySessionStatus
Stored as `VARCHAR(20)`:

- `planned`
- `completed`
- `missed`
- `rescheduled`
- `cancelled`

### ScheduleImportBatchStatus
Stored as `VARCHAR(20)`:

- `uploaded`
- `validated`
- `committed`
- `failed`
- `cancelled`

### ScheduleImportRowStatus
Stored as `VARCHAR(16)`:

- `valid`
- `invalid`
- `duplicate`

### ReminderStatus
Stored as `VARCHAR(20)`:

- `scheduled`
- `sent`
- `cancelled`

### ReminderAnchor
Stored as `VARCHAR(32)` and nullable:

- `task_deadline`
- `subtask_deadline`
- `session_start`

When `anchor` is `NULL`, the reminder is absolute and `trigger_at` is authoritative.

### AiMessageRole
Stored as `VARCHAR(20)`:

- `system`
- `user`
- `assistant`
- `tool`

## 3. Tables

### 3.1 users

Existing Laravel table; Stage 02 adds only the timezone field.

| Column | MySQL type | Null | Notes |
|---|---|---:|---|
| `id` | BIGINT UNSIGNED | NO | PK |
| `name` | VARCHAR(255) | NO | existing |
| `email` | VARCHAR(255) | NO | existing unique |
| `email_verified_at` | TIMESTAMP | YES | existing |
| `password` | VARCHAR(255) | NO | existing |
| `remember_token` | VARCHAR(100) | YES | existing |
| `timezone` | VARCHAR(64) | NO | IANA name; DB fallback `UTC` |
| `created_at` | TIMESTAMP | YES | existing |
| `updated_at` | TIMESTAMP | YES | existing |

Constraints/indexes:

- `UNIQUE(email)`.

Eloquent:

- `hasOne(PlanningPreference::class)`
- `hasMany(StudyAvailabilityWindow::class)`
- `hasMany(EducationInstitution::class)`
- `hasMany(AcademicPeriod::class)`
- `hasMany(Subject::class)`
- `hasMany(Teacher::class)`
- `hasMany(Lesson::class)`
- `hasMany(Task::class)`
- `hasMany(StudySession::class)`
- `hasMany(Reminder::class)`
- `hasMany(ScheduleImportBatch::class)`
- `hasMany(AiConversation::class)`

---

### 3.2 planning_preferences

At most one row per user. The primary key prevents multiple preference rows. The singleton API returns an all-null configuration without persistence on GET when missing; complete PUT materializes/replaces the current user’s row. Nullable constraints use documented system defaults when implemented; this specification does not choose product default study hours, break duration, or session bounds.

| Column | MySQL type | Null | Notes |
|---|---|---:|---|
| `user_id` | BIGINT UNSIGNED | NO | PK + FK |
| `max_daily_study_minutes` | SMALLINT UNSIGNED | YES | NULL = system default |
| `max_weekly_study_minutes` | SMALLINT UNSIGNED | YES | NULL = system default |
| `preferred_break_minutes` | SMALLINT UNSIGNED | YES | NULL = system default |
| `min_session_minutes` | SMALLINT UNSIGNED | YES | NULL = system default |
| `max_session_minutes` | SMALLINT UNSIGNED | YES | NULL = system default |
| `created_at` | TIMESTAMP | YES | |
| `updated_at` | TIMESTAMP | YES | |

FK:

- `user_id -> users.id ON DELETE CASCADE`.

Checks:

- every configured minutes field is `> 0`;
- if both session bounds are configured: `max_session_minutes >= min_session_minutes`;
- if both workload limits are configured: `max_weekly_study_minutes >= max_daily_study_minutes`.

Eloquent:

- `belongsTo(User::class)`.

---

### 3.3 study_availability_windows

Represents recurring local study windows.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `day_of_week` | TINYINT UNSIGNED | NO |
| `starts_at` | TIME | NO |
| `ends_at` | TIME | NO |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

Semantics:

- `day_of_week`: ISO `1 = Monday ... 7 = Sunday`;
- overnight availability is represented as two rows;
- overlapping windows for the same user/day are rejected by the implemented `StudyAvailabilityService`; adjacency is accepted and PATCH excludes its own row;
- the API uses local `HH:MM:SS`, with `24:00:00` permitted only as an end boundary; no UTC conversion or automatic overnight splitting is performed.

FK:

- `user_id -> users.id ON DELETE CASCADE`.

Checks:

- `day_of_week BETWEEN 1 AND 7`;
- `starts_at < ends_at`.

Indexes:

- `INDEX(user_id, day_of_week)`;
- `UNIQUE(user_id, day_of_week, starts_at, ends_at)`.

---

### 3.4 education_institutions

User-owned academic reference data.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `name` | VARCHAR(255) | NO |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

FK:

- `user_id -> users.id ON DELETE CASCADE`.

Indexes:

- `UNIQUE(user_id, name)`.

Eloquent:

- `belongsTo(User::class)`
- `hasMany(AcademicPeriod::class)`
- `hasMany(Subject::class)`
- `hasMany(Teacher::class)`

---

### 3.5 academic_periods

Explicit semester/academic-period boundary.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `education_institution_id` | BIGINT UNSIGNED | YES |
| `name` | VARCHAR(255) | NO |
| `starts_on` | DATE | NO |
| `ends_on` | DATE | NO |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `education_institution_id -> education_institutions.id ON DELETE SET NULL`.

Checks:

- `starts_on <= ends_on`.

Indexes:

- `INDEX(user_id, starts_on, ends_on)`;
- `UNIQUE(user_id, name, starts_on)`.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(EducationInstitution::class)`
- `hasMany(Lesson::class)`
- `hasMany(ScheduleImportBatch::class)`

---

### 3.6 subjects

User-owned subject catalog.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `education_institution_id` | BIGINT UNSIGNED | YES |
| `name` | VARCHAR(255) | NO |
| `code` | VARCHAR(50) | YES |
| `color` | CHAR(7) | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `education_institution_id -> education_institutions.id ON DELETE SET NULL`.

Checks:

- `color IS NULL OR color REGEXP '^#[0-9A-Fa-f]{6}$'`.

Indexes:

- `UNIQUE(user_id, name)`.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(EducationInstitution::class)`
- `hasMany(Lesson::class)`
- `hasMany(Task::class)`

---

### 3.7 teachers

User-owned teacher metadata.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `education_institution_id` | BIGINT UNSIGNED | YES |
| `name` | VARCHAR(255) | NO |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `education_institution_id -> education_institutions.id ON DELETE SET NULL`.

Indexes:

- `INDEX(user_id, name)`.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(EducationInstitution::class)`
- `hasMany(Lesson::class)`

---

### 3.8 schedule_import_batches

Implemented persistence for import staging/history; import processing itself is not implemented.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `academic_period_id` | BIGINT UNSIGNED | NO |
| `status` | VARCHAR(20) | NO |
| `original_filename` | VARCHAR(255) | NO |
| `file_hash` | CHAR(64) | NO |
| `total_rows` | INT UNSIGNED | NO |
| `valid_rows` | INT UNSIGNED | NO |
| `invalid_rows` | INT UNSIGNED | NO |
| `duplicate_rows` | INT UNSIGNED | NO |
| `error_message` | TEXT | YES |
| `committed_at` | DATETIME | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

Defaults:

- all row counters default to `0`;
- status defaults to `uploaded`.

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `academic_period_id -> academic_periods.id ON DELETE RESTRICT`.

Checks:

- `schedule_import_batches_valid_status`: `status IN ('uploaded', 'validated', 'committed', 'failed', 'cancelled')`.

Indexes:

- `INDEX(user_id, academic_period_id, created_at)`;
- `INDEX(user_id, file_hash)`.

No unique constraint on `file_hash`: the same file may be uploaded again for preview, while duplicate protection must be enforced by the future Lesson/import commit behavior.

The non-unique period/date index is named `schedule_import_batches_user_period_created_index` to stay within MySQL's identifier-length limit; the hash index is `schedule_import_batches_user_id_file_hash_index`.

Implemented Eloquent:

- `belongsTo(User::class)` as `user`;
- `belongsTo(AcademicPeriod::class)` as `academicPeriod`;
- `hasMany(ScheduleImportRow::class)` as `rows`;
- `hasMany(Lesson::class)` as `lessons`;
- `status` casts to `ScheduleImportBatchStatus`, `committed_at` to datetime, and the four counters to integers.

---

### 3.9 schedule_import_rows

Implemented staging/preview-row persistence for an import batch; JSON fixtures do not define the future normalization contract.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `schedule_import_batch_id` | BIGINT UNSIGNED | NO |
| `row_number` | INT UNSIGNED | NO |
| `status` | VARCHAR(16) | NO |
| `raw_data` | JSON | NO |
| `normalized_data` | JSON | YES |
| `validation_errors` | JSON | YES |
| `fingerprint` | CHAR(64) | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

FK:

- `schedule_import_batch_id -> schedule_import_batches.id ON DELETE CASCADE`.

Checks:

- `schedule_import_rows_positive_row_number`: `row_number > 0`;
- `schedule_import_rows_valid_status`: `status IN ('valid', 'invalid', 'duplicate')`.

Indexes:

- `UNIQUE(schedule_import_batch_id, row_number)`;
- `INDEX(schedule_import_batch_id, status)`.

The unique constraint is named `schedule_import_rows_schedule_import_batch_id_row_number_unique`; the status index is `schedule_import_rows_schedule_import_batch_id_status_index`. `fingerprint` is nullable and non-unique; matching fingerprints can be stored within one batch or across batches. The V1 identity contract is fixed in section 3.10; fingerprint generation remains unimplemented.

Implemented Eloquent:

- `belongsTo(ScheduleImportBatch::class)` as `scheduleImportBatch`;
- `status` casts to `ScheduleImportRowStatus`, `row_number` to integer, and `raw_data`, `normalized_data`, and `validation_errors` to arrays, preserving nullable metadata.

---

### 3.10 lessons

Implemented persistence for a concrete schedule occurrence; schedule APIs and business Services remain unimplemented.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `academic_period_id` | BIGINT UNSIGNED | YES |
| `subject_id` | BIGINT UNSIGNED | NO |
| `teacher_id` | BIGINT UNSIGNED | YES |
| `schedule_import_batch_id` | BIGINT UNSIGNED | YES |
| `replaces_lesson_id` | BIGINT UNSIGNED | YES |
| `type` | VARCHAR(32) | NO |
| `room` | VARCHAR(100) | YES |
| `starts_at` | DATETIME | NO |
| `ends_at` | DATETIME | NO |
| `status` | VARCHAR(20) | NO |
| `import_fingerprint` | CHAR(64) | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |
| `deleted_at` | TIMESTAMP | YES |

Defaults:

- `type = 'lecture'`;
- `status = 'active'`.

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `academic_period_id -> academic_periods.id ON DELETE RESTRICT`;
- `subject_id -> subjects.id ON DELETE RESTRICT`;
- `teacher_id -> teachers.id ON DELETE SET NULL`;
- `schedule_import_batch_id -> schedule_import_batches.id ON DELETE SET NULL`;
- `replaces_lesson_id -> lessons.id ON DELETE SET NULL`.

DB-enforced CHECK constraints:

- `lessons_valid_interval`: `starts_at < ends_at`;
- `lessons_valid_status`: `status IN ('active', 'cancelled', 'replaced')`;
- `lessons_valid_type`: `type IN ('lecture', 'practical', 'laboratory', 'seminar', 'consultation', 'exam', 'other')`;
- `lessons_import_requires_period`: `import_fingerprint IS NULL OR academic_period_id IS NOT NULL`.

The intended self-replacement check `replaces_lesson_id IS NULL OR replaces_lesson_id <> id` is deliberately absent. MySQL 8.4.10 rejects a CHECK referencing AUTO_INCREMENT `id` (3818), and independently rejects a CHECK involving the self-FK column with `ON DELETE SET NULL` (3823). No trigger or generated-column workaround is used. The FK enforces original existence and the unique key permits at most one direct replacement; deterministic application validation must prohibit self-replacement.

Indexes:

- `INDEX(user_id, status, starts_at)`;
- `INDEX(academic_period_id, starts_at)`;
- `UNIQUE(replaces_lesson_id)`;
- `UNIQUE(user_id, academic_period_id, import_fingerprint)`.

Names, respectively: `lessons_user_id_status_starts_at_index`, `lessons_academic_period_id_starts_at_index`, `lessons_replaces_lesson_id_unique`, and `lessons_user_id_academic_period_id_import_fingerprint_unique`.

MySQL allows repeated unique-key entries containing NULL. Manual lessons can therefore repeat with NULL fingerprints, with or without a period. The import-period CHECK prevents a non-NULL fingerprint from bypassing import uniqueness through a NULL period. Soft deletion retains the row and reserves its fingerprint; the unique key also retains any replacement reference. Hard-deleting an original clears its replacement's reference through `SET NULL`; soft deletion does not invoke FK actions.

Important service invariants:

- self-replacement is forbidden;
- subject, teacher, academic period, and import batch must be ownership-compatible with the lesson user;
- replacement and original lesson must belong to the same user;
- the replacement row is `active`, while the original becomes `replaced`;
- `cancelled`, `replaced` and soft-deleted lessons do not block free time;
- import commit restores/updates an existing soft-deleted row with the same fingerprint instead of attempting a new conflicting insert;
- imported lesson identity follows the fixed V1 contract below, scoped by the unique user/academic-period key;
- schedule overlap/conflict detection is handled by a deterministic Service.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(AcademicPeriod::class)`
- `belongsTo(Subject::class)`
- `belongsTo(Teacher::class)`
- `belongsTo(ScheduleImportBatch::class)`
- `belongsTo(Lesson::class, 'replaces_lesson_id')` as `replacedLesson`
- `hasOne(Lesson::class, 'replaces_lesson_id')` as `replacement`

`Lesson` uses `HasFactory` and `SoftDeletes`; `type` / `status` cast to `LessonType` / `LessonStatus`, and `starts_at` / `ends_at` use datetime casts. Concrete DATETIME values represent UTC. Casting does not replace application-level UTC input validation. The manual factory leaves period, teacher, import batch, original, room, and fingerprint NULL and produces an active lecture with an ordered UTC interval.

#### Fixed V1 import fingerprint contract

After subject resolution, the canonical subject component is the persisted Subject database ID only, expressed as a positive ASCII decimal string without leading zeros or floating-point conversion. Subject name/code, teacher, room, and lesson type are excluded. Text normalization is not part of fingerprinting; resolving source names/codes to a Subject remains a separate future importer responsibility. Re-importing the same Subject ID and interval preserves identity across renames; resolving to a different Subject ID changes identity.

Serialize both instants explicitly in UTC as `YYYY-MM-DDTHH:MM:SSZ`, with whole-second precision and no fractional seconds, independent of PHP/server default timezone. Persisted lesson DATETIME values represent UTC.

Canonical bytes are an ordered JSON array of exactly three strings: `[subject_id, starts_at_utc, ends_at_utc]`, encoded with `JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`, without pretty printing, BOM, or trailing newline. Compute `hash('sha256', canonical_serialization, false)` to obtain lowercase 64-character hexadecimal SHA-256.

Verified example: Subject ID `42`, UTC start `2026-10-05T06:00:00Z`, UTC end `2026-10-05T07:30:00Z`:

```text
["42","2026-10-05T06:00:00Z","2026-10-05T07:30:00Z"]
```

Result: `aaef02b0a645f0b15b7583ce6b0f21ece2ab2f8ea3d7a8dd2f0c1e6104ed8806`.

The contract is fixed; fingerprint generation, subject resolution, duplicate resolution, and import commit are not implemented by this persistence slice. Tests store the verified result without adding a generator Service.

---

### 3.11 tasks

Implemented persistence for user-owned academic work; task workflows remain future Service work.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `subject_id` | BIGINT UNSIGNED | YES |
| `title` | VARCHAR(255) | NO |
| `description` | TEXT | YES |
| `status` | VARCHAR(20) | NO |
| `priority` | TINYINT UNSIGNED | NO |
| `estimated_minutes` | SMALLINT UNSIGNED | YES |
| `deadline_at` | DATETIME | YES |
| `completed_at` | DATETIME | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |
| `deleted_at` | TIMESTAMP | YES |

Defaults:

- `status = 'pending'`;
- `priority = 2`.

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `subject_id -> subjects.id ON DELETE SET NULL`.

DB-enforced CHECKs:

- `tasks_valid_status`: `status IN ('pending', 'completed')`;
- `tasks_valid_priority`: `priority BETWEEN 1 AND 3`;
- `tasks_positive_estimate`: `estimated_minutes IS NULL OR estimated_minutes > 0`;
- `tasks_completion_consistency`: `(status = 'completed' AND completed_at IS NOT NULL) OR (status = 'pending' AND completed_at IS NULL)`.

Indexes:

- `INDEX(user_id, status, deadline_at)`;
- `INDEX(user_id, subject_id, status)`.

Derived:

- `overdue = status != completed AND deadline_at < now`.

Future Task/Reminder Service deletion behavior (not implemented by model soft deletion):

- application-level task deletion is soft delete;
- subtasks are soft deleted by the Task service;
- future planned sessions are marked `cancelled`;
- historical completed/missed/rescheduled sessions remain;
- scheduled reminders targeting the task are cancelled or removed by the Reminder service.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(Subject::class)`
- `hasMany(Subtask::class)`
- `hasMany(StudySession::class)`
- `hasMany(Reminder::class)`

---

### 3.12 subtasks

Implemented persistence for ordered decomposition of a task.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `task_id` | BIGINT UNSIGNED | NO |
| `title` | VARCHAR(255) | NO |
| `description` | TEXT | YES |
| `position` | SMALLINT UNSIGNED | NO |
| `status` | VARCHAR(20) | NO |
| `estimated_minutes` | SMALLINT UNSIGNED | YES |
| `deadline_at` | DATETIME | YES |
| `completed_at` | DATETIME | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |
| `deleted_at` | TIMESTAMP | YES |

Defaults:

- `status = 'pending'`.

FK:

- `task_id -> tasks.id ON DELETE CASCADE`.

DB-enforced CHECKs:

- `subtasks_positive_position`: `position > 0`;
- `subtasks_valid_status`: `status IN ('pending', 'completed')`;
- `subtasks_positive_estimate`: `estimated_minutes IS NULL OR estimated_minutes > 0`;
- `subtasks_completion_consistency`: `(status = 'completed' AND completed_at IS NOT NULL) OR (status = 'pending' AND completed_at IS NULL)`.

Indexes:

- `INDEX(task_id, position)`;
- `INDEX(task_id, status, deadline_at)`.

Service invariants:

- if parent deadline and subtask deadline both exist, subtask deadline must not exceed the task deadline unless a later product decision explicitly allows warning-only behavior;
- reordering is transactional;
- when active (non-soft-deleted) subtasks exist, the subtasks are authoritative for planning effort and the parent task estimate is not double-counted; completed subtasks do not add remaining effort;
- if no active subtasks exist, the task's own estimate is used directly.

Eloquent:

- `belongsTo(Task::class)`
- `hasMany(StudySession::class)`
- `hasMany(Reminder::class)`

---

### 3.13 study_sessions

Implemented persistence for a concrete reserved study block; planning and rescheduling Services remain unimplemented. No soft deletes.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `task_id` | BIGINT UNSIGNED | NO |
| `subtask_id` | BIGINT UNSIGNED | YES |
| `rescheduled_from_session_id` | BIGINT UNSIGNED | YES |
| `starts_at` | DATETIME | NO |
| `ends_at` | DATETIME | NO |
| `status` | VARCHAR(20) | NO |
| `completed_at` | DATETIME | YES |
| `actual_minutes` | SMALLINT UNSIGNED | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

Default:

- `status = 'planned'`.

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `task_id -> tasks.id ON DELETE CASCADE`;
- `subtask_id -> subtasks.id ON DELETE SET NULL`;
- `rescheduled_from_session_id -> study_sessions.id ON DELETE SET NULL`.

DB-enforced CHECKs:

- `study_sessions_valid_interval`: `starts_at < ends_at`;
- `study_sessions_valid_status`: `status IN ('planned', 'completed', 'missed', 'rescheduled', 'cancelled')`;
- `study_sessions_positive_actual_minutes`: `actual_minutes IS NULL OR actual_minutes > 0`;
- `study_sessions_completion_consistency`: `(status = 'completed' AND completed_at IS NOT NULL) OR (status <> 'completed' AND completed_at IS NULL)`;
- `study_sessions_actual_requires_completion`: `actual_minutes IS NULL OR status = 'completed'`.

The self-rescheduling CHECK is intentionally omitted: MySQL prohibits the AUTO_INCREMENT comparison and CHECKs involving the self-FK's `SET NULL` action (3818/3823). There is no trigger or generated-column workaround. The FK enforces predecessor existence and `UNIQUE(rescheduled_from_session_id)` enforces at most one direct successor; multiple NULL predecessors are allowed. Self-reference remains physically possible but is invalid domain data.

Indexes:

- `INDEX(user_id, status, starts_at)`;
- `INDEX(task_id, starts_at)`;
- `INDEX(subtask_id, starts_at)`;
- `UNIQUE(rescheduled_from_session_id)`.

Service invariants:

- self-rescheduling is forbidden;
- if `subtask_id` is set, that subtask must belong to `task_id`;
- task, subtask and session must resolve to the same owner;
- sessions may not overlap active lessons or other blocking study sessions;
- no generated session may end after its task/subtask deadline;
- rescheduling produces a new row that references the previous row; the previous row becomes `rescheduled`.

Planned vs actual:

- planned minutes = `ends_at - starts_at`;
- actual minutes = `actual_minutes`;
- if the first V1 UI supports only "mark complete", the Service may default `actual_minutes` to scheduled duration, but the schema keeps the concepts separate.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(Task::class)`
- `belongsTo(Subtask::class)`
- `belongsTo(StudySession::class, 'rescheduled_from_session_id')` as `rescheduledFromSession`
- `hasOne(StudySession::class, 'rescheduled_from_session_id')` as `rescheduledToSession`
- `hasMany(Reminder::class)`

---

### 3.14 reminders

Implemented reminder persistence with optional typed domain targets; target validation, trigger recalculation, and delivery remain unimplemented.

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `task_id` | BIGINT UNSIGNED | YES |
| `subtask_id` | BIGINT UNSIGNED | YES |
| `study_session_id` | BIGINT UNSIGNED | YES |
| `message` | VARCHAR(500) | YES |
| `trigger_at` | DATETIME | NO |
| `anchor` | VARCHAR(32) | YES |
| `offset_minutes` | SMALLINT | YES |
| `status` | VARCHAR(20) | NO |
| `sent_at` | DATETIME | YES |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

Default:

- `status = 'scheduled'`.

FK:

- `user_id -> users.id ON DELETE CASCADE`;
- `task_id -> tasks.id ON DELETE SET NULL`;
- `subtask_id -> subtasks.id ON DELETE SET NULL`;
- `study_session_id -> study_sessions.id ON DELETE SET NULL`.

DB-enforced CHECKs (none reference SET NULL target columns):

- `reminders_valid_status`: `status IN ('scheduled', 'sent', 'cancelled')`;
- `reminders_valid_anchor`: `anchor IS NULL OR anchor IN ('task_deadline', 'subtask_deadline', 'session_start')`;
- `reminders_anchor_offset_pair`: `(anchor IS NULL AND offset_minutes IS NULL) OR (anchor IS NOT NULL AND offset_minutes IS NOT NULL)`;
- `reminders_sent_consistency`: `(status = 'sent' AND sent_at IS NOT NULL) OR (status IN ('scheduled', 'cancelled') AND sent_at IS NULL)`.

Future Reminder Service invariants:

- at most one of `task_id`, `subtask_id`, `study_session_id` is non-NULL;
- `task_deadline`, `subtask_deadline`, and `session_start` require their matching target;
- all targets belong to the reminder's user;
- trigger recalculation follows anchor/offset and target changes;
- reconcile targets, relative metadata, and lifecycle before hard purge.

MySQL rejects CHECKs involving these `ON DELETE SET NULL` FK columns (3823), so target count and compatibility are intentionally application-level rules. Tests demonstrate that multiple targets, mismatched anchors, and cross-owner references are physically permitted but invalid domain data. Target hard deletion clears its FK and leaves anchor/offset metadata unchanged; the future Service must reconcile that metadata. No trigger is used.

Offset semantics:

`trigger_at = anchor_time + offset_minutes`

Example: 60 minutes before a task deadline => `offset_minutes = -60`.

Indexes:

- `INDEX(status, trigger_at)` for delivery workers;
- `INDEX(user_id, status, trigger_at)` for user queries.

General reminders are valid: all three target IDs may be null when `anchor` is null.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(Task::class)`
- `belongsTo(Subtask::class)`
- `belongsTo(StudySession::class)`

---

### 3.15 ai_conversations

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `user_id` | BIGINT UNSIGNED | NO |
| `created_at` | TIMESTAMP | YES |
| `updated_at` | TIMESTAMP | YES |

FK:

- `user_id -> users.id ON DELETE CASCADE`.

Indexes:

- `INDEX(user_id, updated_at)`.

Eloquent:

- `belongsTo(User::class)`
- `hasMany(AiMessage::class)`

---

### 3.16 ai_messages

| Column | MySQL type | Null |
|---|---|---:|
| `id` | BIGINT UNSIGNED | NO |
| `conversation_id` | BIGINT UNSIGNED | NO |
| `role` | VARCHAR(20) | NO |
| `content` | LONGTEXT | YES |
| `tool_calls` | JSON | YES |
| `tool_call_id` | VARCHAR(255) | YES |
| `created_at` | TIMESTAMP | YES |

FK:

- `conversation_id -> ai_conversations.id ON DELETE CASCADE`.

Checks:

- role in approved AI message roles.

Indexes:

- `INDEX(conversation_id, created_at)`;
- `INDEX(tool_call_id)`.

Eloquent:

- `belongsTo(AiConversation::class)`.

Messages are treated as append-oriented records; `updated_at` is absent. `AiMessage::UPDATED_AT = null` keeps automatic `created_at` support without writing an update timestamp. Role casts to `AiMessageRole`, tool calls to array, and creation time to datetime. Append-oriented usage is a future application convention, not database immutability; Eloquent updates remain schema-compatible. No soft deletes.

## 4. Delete behavior matrix

| Parent/entity | Normal domain action | FK/hard-delete behavior |
|---|---|---|
| User | account lifecycle handled separately | cascade personal data |
| PlanningPreference | replace/update | cascade with user |
| StudyAvailabilityWindow | hard delete allowed | cascade with user |
| EducationInstitution | hard delete if desired | child institution refs set null |
| AcademicPeriod | should not be removed while schedule/import data exists | restrict from lessons/import batches |
| Subject | retain while lessons depend on it | lessons restrict; tasks set null |
| Teacher | hard delete allowed | lessons set null |
| ScheduleImportBatch | may be removed after lifecycle if desired | rows cascade; lessons keep data and set batch ref null |
| Lesson | soft delete | hard purge only administrative/account cleanup |
| Task | soft delete | subtasks/session cleanup only on hard purge |
| Subtask | soft delete | sessions set subtask ref null only on hard purge |
| StudySession | lifecycle status, not soft delete | target reminders set null |
| Reminder | scheduled row may be cancelled/removed | cascade with user |
| AiConversation | hard delete allowed | messages cascade |

## 5. Important cross-row/service rules not delegated to MySQL

These are deterministic application responsibilities, separate from MySQL enforcement. Recurring availability overlap, current API ownership protection, and Academic Context institution/deletion invariants are implemented; other domain rules remain future work:

1. lesson overlap and schedule conflicts;
2. study-session overlap and free-time validation;
3. cross-user ownership checks (implemented for current profile/preferences/availability and Academic Context APIs; Lesson/Task/import/Reminder association compatibility remains pending);
4. subject/teacher/academic-period institution ownership compatibility (implemented by Academic Services); ownership of their references from future Lesson/Task/import operations remains pending;
5. replacement lesson and original lesson ownership;
6. subtask belongs to the study session's task;
7. deadline feasibility;
8. daily and weekly workload limits;
9. recurring availability overlap (implemented by `StudyAvailabilityService`);
10. parent-task versus subtask effort authority;
11. reminder target ownership;
12. relative reminder recalculation after deadline/session changes;
13. import duplicate resolution and restore/update behavior;
14. progress/statistics calculations;
15. rescheduling feasibility.

## 6. Derived data intentionally not stored

No V1 tables/columns are created for:

- free time slots;
- overdue flags;
- task progress percentage;
- daily/weekly workload totals;
- planning conflicts/warnings;
- generic progress metric snapshots;
- planning runs;
- generated report history;
- reminder delivery attempts.

These can be added later only if a concrete audit, retention or performance requirement appears.

## 7. Migration implementation order

Continue implementation in small dependency-safe groups rather than one large migration.

Recommended sequence:

1. **Completed:** extend `users` with `timezone`;
2. **Completed:** `planning_preferences`, `study_availability_windows`;
3. **Completed:** `education_institutions`, `academic_periods`, `subjects`, `teachers`;
4. **Completed:** `schedule_import_batches`, `schedule_import_rows` (persistence only);
5. **Completed:** `lessons` (persistence only);
6. **Completed:** `tasks`, `subtasks`;
7. **Completed:** `study_sessions`;
8. **Completed:** `reminders`;
9. **Completed:** `ai_conversations`, `ai_messages`.

Each group should include:

- Eloquent models/relations needed for that group;
- factories where useful;
- migration rollback coverage;
- database constraints;
- focused MySQL-backed tests;
- ownership/FK tests where relevant.

## 8. Implementation issues and gate

Migration Groups 1–9 are implemented and verified; Stage 02 persistence is complete. MySQL constraint support has been verified, and the unsupported checks have explicit application-level enforcement decisions rather than unresolved migration blockers. The minimum constraint-enforcement baseline is **MySQL >= 8.0.16**; the currently verified development/test server is **MySQL 8.4.10**, using the dedicated `student_planner_testing` database. The verified local version is not an exact production-version pin.

The latest completed Groups 1–9 verification passed the new Task/StudySession/Reminder/AI focused suites (100 tests / 1,052 assertions), the combined Lesson/Schedule Import/Academic Context/Planning persistence and migration regression suite (185 tests / 1,513 assertions), and the full suite (287 tests / 2,571 assertions), each with PHPUnit process exit code 0. Pint verification, `git diff --check`, and full domain rollback/reapply verification also passed. These counts record current implementation verification, not permanent architectural requirements.

All Stage 02 migration enforcement decisions are resolved. Approved FK actions are preserved, with future deterministic Services responsible for the cross-row invariants below. Services and lifecycle workflows are not implemented by these persistence models.

### 8.1 Self-reference CHECK restrictions

MySQL prohibits CHECK expressions referencing an `AUTO_INCREMENT` column. The Lesson design gate confirmed error 3818 on MySQL 8.4.10; Lessons and StudySessions deliberately omit self-comparison CHECKs and assign self-replacement/self-rescheduling prohibitions to future deterministic Services. Persistence tests explicitly demonstrate physically permitted self-reference as invalid domain data. FKs enforce referenced existence, and unique references enforce one direct replacement/successor. No triggers or generated-column workarounds are used. [MySQL CHECK constraint restrictions](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).

### 8.2 CHECK constraints and referential actions

The Lesson design gate independently confirmed error 3823 for a CHECK involving a FK column with `ON DELETE SET NULL`. Lesson and StudySession self FKs remain unchanged. Reminder CHECKs do not reference target FKs: MySQL enforces status, nullable anchor vocabulary, anchor/offset pairing, and sent-state consistency. Target count, anchor/target compatibility, ownership, recalculation, and purge reconciliation belong to a future Reminder Service. The lesson import-period CHECK is supported with its `RESTRICT` FK and remains enforced as `lessons_import_requires_period`. [MySQL CHECK constraint restrictions](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).

Nulling a relative reminder's target leaves its persisted anchor/offset unchanged. Tests confirm this behavior; future hard-purge code must reconcile the relative metadata and reminder lifecycle before deleting the target. Database persistence is not a substitute for that application cleanup.

| Area | Database enforcement | Future deterministic Service enforcement |
|---|---|---|
| StudySession | interval, status, completion consistency, positive actual minutes only when completed, FK existence/actions, unique predecessor | self-rescheduling prohibition, task/subtask compatibility, ownership, overlaps, deadlines, lifecycle transition |
| Reminder | status, nullable anchor vocabulary, anchor/offset pairing, sent-state consistency, FK existence/actions | max one target, target/anchor compatibility, ownership, trigger recalculation, hard-purge reconciliation |

### 8.3 Diagram representation

The supplied DBML uses `bigint`, `smallint`, `tinyint`, and `int` shorthand, with some unsigned attributes expressed only in notes. All keys and unsigned numeric values must follow the exact Markdown types, including unsigned `day_of_week` and signed `reminders.offset_minutes`. The diagram does not encode all CHECK expressions or Service invariants and must not be treated as a complete executable migration source.

### 8.4 Remaining application work and product decisions

- Implement fingerprint generation and import commit later using the fixed contract in section 3.10; normalization/serialization is no longer an unresolved design question. Groups 4–5 store fingerprints without generating them.
- Define product defaults and any conflict override policy in a future
  planning/product-decision stage; no defaults or override behavior are
  introduced by the completed Stage 03 APIs.
- Account hard-purge ordering must respect the approved restrictive historical references and reminder cleanup. Group 4 tests on MySQL 8.4.10 show that directly deleting a User who owns both a period and its import batch is rejected with error 1451 by `schedule_import_batches_academic_period_id_foreign`. Group 5 confirms that an isolated User/Subject/manual-Lesson graph can cascade, while a combined period/batch/Lesson history graph blocks direct User deletion with 1451. Hard-removing Lessons first still leaves the batch/period restriction; deleting batches next cascades their rows and then permits User deletion. Soft deletion does not remove restrictive references. Account hard purge is an ordered administrative operation, distinct from normal domain deletion; the approved FKs are unchanged.

Stage 02 persistence is complete through Groups 1–9. Stage 03 now implements Authentication/Profile and Planning Preferences/Study Availability APIs, reusable setting/overlap Services, and availability ownership authorization. Stage 04 completes institutions/periods/subjects/teachers APIs and their ownership/relationship/deletion Services. Further backend verticals must add schedule APIs, task/subtask workflows, other domain ownership compatibility, free-time/planning algorithms, rescheduling, and reminder cleanup. Import parsing/preview/commit, fingerprint generation, duplicate resolution, delivery, statistics, and AI orchestration remain unimplemented. Review this specification and the [companion DBML](database-schema.dbml) before any future schema changes.

