# Physical Database Schema

Status: **design baseline approved for implementation planning**\
Scope: Laravel backend + MySQL + Eloquent\
Important: this is the canonical repository adaptation of the approved `stage-02-database-schema-spec.md`. **Domain migrations, model relationships, and the user timezone field have not been implemented.** The current application still contains only its Laravel/Sanctum scaffold.

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

### TaskStatus / SubtaskStatus
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

One row per user. The primary key prevents multiple preference rows; application lifecycle logic must ensure a row is created for each user. Nullable constraints use documented system defaults when implemented; this specification does not choose product default study hours, break duration, or session bounds.

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
- overlapping windows for the same user/day are rejected by a Service.

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

Persistent import process/history.

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

- status in approved import-batch states.

Indexes:

- `INDEX(user_id, academic_period_id, created_at)`;
- `INDEX(user_id, file_hash)`.

No unique constraint on `file_hash`: the same file may be uploaded again for preview, while duplicate protection is enforced when lessons are committed.

---

### 3.9 schedule_import_rows

Staging/preview rows for an import batch.

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

- `row_number > 0`;
- status in approved row states.

Indexes:

- `UNIQUE(schedule_import_batch_id, row_number)`;
- `INDEX(schedule_import_batch_id, status)`.

---

### 3.10 lessons

Concrete schedule occurrence.

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

Checks:

- `starts_at < ends_at`;
- status in approved lesson states;
- type in approved lesson types;
- `replaces_lesson_id IS NULL OR replaces_lesson_id <> id`;
- `import_fingerprint IS NULL OR academic_period_id IS NOT NULL`.

Indexes:

- `INDEX(user_id, status, starts_at)`;
- `INDEX(academic_period_id, starts_at)`;
- `UNIQUE(replaces_lesson_id)`;
- `UNIQUE(user_id, academic_period_id, import_fingerprint)`.

Important service invariants:

- replacement and original lesson must belong to the same user;
- the replacement row is `active`, while the original becomes `replaced`;
- `cancelled`, `replaced` and soft-deleted lessons do not block free time;
- import commit restores/updates an existing soft-deleted row with the same fingerprint instead of attempting a new conflicting insert;
- imported lesson identity is a deterministic SHA-256 fingerprint based on normalized subject identity/name, UTC start instant, and UTC end instant, scoped by the unique user/academic-period key;
- teacher, room, and lesson type are excluded from duplicate identity because changes can update the same occurrence; the exact normalization and serialization contract still needs to be fixed before import implementation;
- schedule overlap/conflict detection is handled by a deterministic Service.

Eloquent:

- `belongsTo(User::class)`
- `belongsTo(AcademicPeriod::class)`
- `belongsTo(Subject::class)`
- `belongsTo(Teacher::class)`
- `belongsTo(ScheduleImportBatch::class)`
- `belongsTo(Lesson::class, 'replaces_lesson_id')` as `replacedLesson`
- `hasOne(Lesson::class, 'replaces_lesson_id')` as `replacement`

---

### 3.11 tasks

User-owned academic work.

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

Checks:

- status in approved task states;
- `priority BETWEEN 1 AND 3`;
- `estimated_minutes IS NULL OR estimated_minutes > 0`;
- completion-state consistency:
  - completed => `completed_at IS NOT NULL`;
  - pending => `completed_at IS NULL`.

Indexes:

- `INDEX(user_id, status, deadline_at)`;
- `INDEX(user_id, subject_id, status)`.

Derived:

- `overdue = status != completed AND deadline_at < now`.

Delete behavior:

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

Ordered decomposition of a task.

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

Checks:

- `position > 0`;
- approved task/subtask status;
- positive estimate when configured;
- completion-state consistency.

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

Concrete reserved study block.

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

Checks:

- `starts_at < ends_at`;
- approved study-session status;
- `actual_minutes IS NULL OR actual_minutes > 0`;
- `status = 'completed'` iff `completed_at IS NOT NULL`;
- `actual_minutes IS NULL OR status = 'completed'`;
- `rescheduled_from_session_id IS NULL OR rescheduled_from_session_id <> id`.

Indexes:

- `INDEX(user_id, status, starts_at)`;
- `INDEX(task_id, starts_at)`;
- `INDEX(subtask_id, starts_at)`;
- `UNIQUE(rescheduled_from_session_id)`.

Service invariants:

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
- `belongsTo(StudySession::class, 'rescheduled_from_session_id')`
- `hasOne(StudySession::class, 'rescheduled_from_session_id')`
- `hasMany(Reminder::class)`

---

### 3.14 reminders

Reminder with an optional typed domain target.

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

Checks:

- approved reminder status;
- at most one of `task_id`, `subtask_id`, `study_session_id` is non-null;
- `anchor` and `offset_minutes` are either both null or both non-null;
- anchor target compatibility:
  - `task_deadline` requires `task_id`;
  - `subtask_deadline` requires `subtask_id`;
  - `session_start` requires `study_session_id`;
- sent-state consistency:
  - sent => `sent_at IS NOT NULL`;
  - non-sent => `sent_at IS NULL`.

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

Messages are treated as append-oriented records; `updated_at` is not required.

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

These remain deterministic PHP Service responsibilities:

1. lesson overlap and schedule conflicts;
2. study-session overlap and free-time validation;
3. cross-user ownership checks;
4. subject/teacher/academic-period ownership compatibility;
5. replacement lesson and original lesson ownership;
6. subtask belongs to the study session's task;
7. deadline feasibility;
8. daily and weekly workload limits;
9. recurring availability overlap;
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

When implementation is approved, migrations should be created in small dependency-safe groups rather than one large migration.

Recommended sequence:

1. extend `users` with `timezone`;
2. `planning_preferences`, `study_availability_windows`;
3. `education_institutions`, `academic_periods`, `subjects`, `teachers`;
4. `schedule_import_batches`, `schedule_import_rows`;
5. `lessons`;
6. `tasks`, `subtasks`;
7. `study_sessions`;
8. `reminders`;
9. `ai_conversations`, `ai_messages`.

Each group should include:

- Eloquent models/relations needed for that group;
- factories where useful;
- migration rollback coverage;
- database constraints;
- focused MySQL-backed tests;
- ownership/FK tests where relevant.

## 8. Implementation issues and gate

The approved persistence decisions above are documented, not implemented. The following issues require explicit resolution before the affected migration groups; this document preserves the approved checks and FK actions rather than silently selecting alternatives.

### 8.1 Self-reference CHECK restrictions

The proposed checks comparing `lessons.replaces_lesson_id` and `study_sessions.rescheduled_from_session_id` with their row's `id` reference an `AUTO_INCREMENT` column. MySQL prohibits that in CHECK expressions. The invariant is valid, but its enforcement mechanism requires a design follow-up. [MySQL CHECK constraint restrictions](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).

### 8.2 CHECK constraints and referential actions

The proposed self-reference checks and reminder target/anchor checks also use FK columns configured with `ON DELETE SET NULL`. MySQL disallows CHECK constraints on columns subject to those referential actions. The lesson import-period check should also be reviewed with its `RESTRICT` FK on the selected MySQL version. [MySQL CHECK constraint restrictions](https://dev.mysql.com/doc/refman/8.4/en/create-table-check-constraints.html).

Independently of SQL support, nulling a relative reminder's target would leave its persisted anchor incompatible with that target. Hard-purge behavior must reconcile the anchor/offset and reminder lifecycle before target deletion; no alternative policy is selected here.

### 8.3 Diagram representation

The supplied DBML uses `bigint`, `smallint`, `tinyint`, and `int` shorthand, with some unsigned attributes expressed only in notes. All keys and unsigned numeric values must follow the exact Markdown types, including unsigned `day_of_week` and signed `reminders.offset_minutes`. The diagram does not encode all CHECK expressions or Service invariants and must not be treated as a complete executable migration source.

### 8.4 Remaining implementation questions

- Select and verify the supported MySQL version for constraint enforcement.
- Fix deterministic fingerprint normalization/serialization before import implementation; the identity fields and SHA-256 algorithm are already approved.
- Define product defaults and any conflict override policy in Stage 03; no defaults or override behavior are introduced here.
- Account hard-purge ordering must respect the approved restrictive historical references and reminder cleanup.

Review this specification, the [companion DBML](database-schema.dbml), and the recorded issues before migration implementation is authorized. A later implementation task should start with groups 1-2 (user timezone, planning preferences, availability), use focused MySQL tests, and stop for review before continuing. **This documentation task creates no migrations, models, or application features.**
