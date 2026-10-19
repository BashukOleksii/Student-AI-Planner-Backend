# Earlier Database Draft — Review Notes and Stage 02 Resolutions

H3a adds `ai_pending_actions` for durable confirmation and result replay.
Existing messages/turns alone cannot represent an independently confirmed action,
reviewed arguments, stale-target guard and atomic business receipt. Conversation
and unique turn FKs preserve provenance; ownership derives through conversation.
Task identity stays inside immutable arguments/history so normal soft deletion
cannot erase the review. Cross-row conversation/turn consistency remains enforced
by the Service; the two simple FKs establish existence. CHECKs enforce vocabulary
and receipt/resolution state. Rollback is additive and preserves H2 records. See
[the schema and locking contract](ai-task-actions.md#additive-persistence).

Status: historical draft review; persistence decisions resolved by the approved Stage 02 design. All nine approved persistence groups (Migration Groups 1–9) are implemented; Stage 02 persistence is complete.

The canonical design is [database-schema.md](database-schema.md), with [database-schema.dbml](database-schema.dbml) as its diagram source. The earlier draft below is neither the approved design nor the current application schema. New MySQL implementation issues are recorded in the canonical schema's implementation gate and must be resolved before affected migrations.

Stage E2 extends current application enforcement to confirmed adaptive batches
without changing the approved schema. The existing unique direct-successor key
and lifecycle fields remain authoritative. F1/F2 absolute/relative Reminder management and target reconciliation are implemented; F3a in-app delivery is implemented; external delivery and
administrative purge remain deferred; see [adaptive replanning](adaptive-replanning.md#stage-e2-confirmation-contract).

Stage F1 adds absolute Reminder creation, paginated list/detail, scheduled editing
and explicit cancellation through `ReminderService`, Form Requests, an ownership
Policy and a Resource. Strict UTC/type validation, eligible creation targets,
structural scheduled-source checks and fresh owner-first transactions preserve
history and existing S3/Stage D/E2 cleanup. The strict single-target dependency
helper and physical schema are retained. F2 now adds relative creation/offset editing
and atomic Task/Subtask deadline/completion reconciliation through a focused
synchronization Service and shared target/UTC rules. Session identities retain their
Stage D/E2 retirement policy. F3a adds one native notifications migration with
UUID identity, polymorphic recipient, JSON snapshot and nullable unique Reminder
FK using SET NULL. It preserves delivered inbox history without a second outbox;
account purge must separately address recipient records. Native Notifiable/model
persistence and a single owner-first transaction enforce local idempotence.
External delivery and hard purge remain deferred.
See [reminders.md](reminders.md).

H2 adds only durable chat coordination: `ai_conversation_turns` with an owned
Conversation cascade FK, unique conversation/request UUID, content hash, finite
lease and safe replay result, plus a Conversation/Message ID ordering index.
Existing Conversation/Message columns and records remain unchanged. Short
conversation locks serialize claims/finalization without a network transaction;
this is not a new planning-run, analytics or generic audit entity. See
[the H2 persistence contract](ai-agent-chat.md#persistence-concurrency-and-failure-policy).

## Source and current repository baseline

The supplied Stage 01 review refers to an earlier SQL/ER draft, but no source SQL draft or ER image is present in this repository. Draft-specific observations below are carried forward from that review and remain unverified against the original artifacts. They describe possible design issues, not defects in the current migrations.

The inspected repository retains the four Laravel/Sanctum scaffold migrations and adds Groups 1–9 through sixteen additive domain migrations. Academic/import/Lesson data, Tasks/Subtasks, StudySessions, Reminders, and AI conversations/messages are implemented with Eloquent relationships, PHP backed enums, factories, supported MySQL constraints, and persistence/rollback tests. Stage 02 persistence is complete. The current repository also implements authentication/profile, settings, academic/schedule/import and Task/Subtask APIs, planning Stages A–C and StudySession Stage D. F1/F2 management and F3a in-app Reminder delivery are implemented; external delivery, statistics and AI orchestration remain deferred. Application Services enforce cross-row ownership, exact session work attribution, Lesson replacement and Stage D inspected rescheduling graphs, plus affected scheduled-reminder dependencies. F2 relative/target reconciliation is implemented; administrative purge remains deferred. The [canonical schema](database-schema.md) records the resolved AUTO_INCREMENT/SET NULL enforcement decisions and fixed fingerprint contract; Import B2 implements fingerprint generation. Database configuration is unchanged.

The earlier SQL/ER draft remains rejected and is retained here as historical context. The approved Stage 02 specification and DBML determine the persistence design; Groups 1–9 implement that approved design through additive migrations, preserving the existing scaffold migrations. The historical analysis below is retained; the current schema records the resolved Lesson/StudySession/Reminder enforcement and exact fingerprint serialization decisions.

## 1. Useful concepts already present

The draft already recognizes several important concepts:

- users;
- subjects;
- teachers;
- lessons;
- tasks and subtasks;
- study sessions;
- reminders;
- AI conversations and messages;
- education institutions;
- indexes for common user/date/status access patterns.

These concepts align with major parts of the requirements.

## 2. Earlier draft issues and approved resolutions

### 2.1 One column has two incompatible foreign-key targets

`study_sessions.task_id` is defined as referencing both `tasks.id` and `sub_tasks.id`.

A normal relational foreign-key column cannot safely mean "either table A or table B" with two foreign keys. The original review considered these alternatives:

- explicit `task_id` and nullable `sub_task_id` with invariants;
- a polymorphic target if justified;
- or planning sessions only against one canonical schedulable-work entity.

Do not reproduce the reported dual-FK design in Laravel migrations.

**Resolved:** `study_sessions` has required `task_id` and optional `subtask_id`. A Service ensures that the subtask belongs to that task and that ownership agrees. Rescheduling history uses a separate session self-reference; these are approved design relationships, not implemented models.

### 2.2 Reminder polymorphism is expressed as incompatible foreign keys

The draft has `remindable_type` + `remindable_id`, which resembles a Laravel polymorphic relation, but also adds foreign keys from the same `remindable_id` to several tables.

Those approaches conflict. A polymorphic `morphTo` relation normally cannot have a conventional database FK to several target tables.

**Resolved:** reminders use explicit nullable `task_id`, `subtask_id`, and `study_session_id`, with at most one set. General reminders may have no target. Relative reminders retain compatible anchor/offset and resolved trigger time. The CHECK/FK enforcement issue is recorded in the canonical schema; it does not reinstate the old polymorphic design.

### 2.3 Naming is inconsistent with Laravel conventions

Examples:

- `tasks.users_id` versus `lessons.user_id`;
- `password_hash` versus Laravel's normal authentication expectation of `password` unless customized;
- `education_institutions` used as a foreign-key column name rather than a singular `_id` convention;
- spelling such as `subtituted_lessons_id`.

**Resolved:** the approved schema uses standard singular `_id` columns, Laravel's existing `password`, `subtasks`, and the lesson replacement self-reference. Exact names are recorded in the canonical schema rather than inherited from the draft.

### 2.4 Named SQL types appear non-portable for MySQL

The draft uses types such as `class_type` / diagram enum types. MySQL normally needs a concrete column representation (for example string/enum/tiny integer), and Laravel migrations must express the actual type explicitly.

**Resolved:** use PHP backed enums with bounded VARCHAR lifecycle columns and TINYINT UNSIGNED priority (LOW = 1, NORMAL = 2, HIGH = 3). MySQL ENUM is not the baseline strategy. Task/subtask statuses are `pending` and `completed`; overdue is derived.

### 2.5 Subject/teacher ownership is unclear

The reviewed draft points `subjects` and `teachers` to an education institution but not to a user. The review asked whether they should be:

- global institution reference data;
- user-owned custom data;
- or shared templates copied into a user's context.

**Resolved:** Subject, Teacher, and EducationInstitution are all user-owned in V1. Subject and Teacher may optionally reference an institution owned by the same user. They are not global catalogs.

### 2.6 Academic period / semester is missing

Requirements mention re-importing a schedule for a new semester, but the reviewed draft has no explicit period/semester boundary.

**Resolved:** persist `academic_periods` and require an explicit period for import batches. Importing a new semester does not implicitly delete historical periods; schedule/import references protect periods from destructive deletion.

### 2.7 Planning preferences are opaque JSON

The draft's `users.planning_settings` JSON could be convenient, but deterministic planning needs typed constraints.

**Resolved:** use one `planning_preferences` row per user for numeric limits, separate recurring `study_availability_windows`, and `users.timezone`. Concrete domain instants use UTC DATETIME and recurring local windows use TIME. Nullable numeric values permit system defaults, whose product values remain undecided. Import staging and AI tool metadata may use JSON; deterministic preferences do not.

### 2.8 Import lifecycle is not represented

The requirements require preview, validation errors, and re-import behavior. The reviewed draft reportedly has no import batch/history concept.

**Resolved:** persist `schedule_import_batches` and `schedule_import_rows`. Imported lesson duplicate identity uses SHA-256 over normalized subject identity/name and start/end instants, scoped by user and academic period; teacher, room, and type are excluded. Commit resolves existing rows, including restoration/update of matching soft-deleted lessons. Exact encoding/normalization remains implementation work.

### 2.9 Completion history may be insufficient for some statistics

The requirements include "completed on time vs late" and planned-vs-actual analysis. A current status alone may not preserve when completion occurred.

**Resolved:** persist task/subtask/session completion timing and optional session actual minutes. Scheduled and actual workload are separate; statistics, workload totals, progress percentages, and overdue remain derived rather than duplicated in V1 snapshots.

### 2.10 Soft-delete behavior needs domain justification

Several draft tables use `deleted_at` without clear historical requirements.

**Resolved:** normal deletion soft-deletes lessons, tasks, and subtasks. Sessions retain lifecycle history without soft deletes. Task deletion cancels affected future planned sessions while retaining completed/missed/rescheduled history. Teacher removal may null lesson references; subjects referenced by lessons and periods with schedule/import history are protected. Full hard-delete FK actions and normal domain behavior are distinguished in the canonical delete matrix.

## 3. Index notes

The draft correctly anticipates indexes around:

- user + lesson date/time;
- user + task status/deadline/priority;
- user + study-session time/status;
- reminder send state + trigger time;
- conversation/message ordering.

The approved Stage 02 indexes and unique constraints are now listed table by table in [database-schema.md](database-schema.md). The draft's index suggestions do not override them.

## 4. Stage 02 outcome and remaining work

The former persistence questions are resolved by [the approved physical design](database-schema.md). [Architecture](architecture.md), [the conceptual domain model](domain-model.md), and [business rules](business-rules.md) now reflect those decisions. This review preserves the earlier problems and their resolutions rather than describing the old draft as implemented code.

Additional approved decisions:

- lesson replacements use `lessons.replaces_lesson_id`: the original is `replaced`, the replacement is `active`;
- active (non-soft-deleted) subtasks are authoritative for effort; with none, use the task estimate directly;
- free slots, overdue flags, progress percentages, workload totals, conflicts, and general metric snapshots are not persisted;
- planning-run, report-history, and reminder-delivery-history tables are excluded from V1.

Stage 02 persistence is complete. MySQL enforcement decisions are resolved: unsupported checks are assigned to deterministic Services, while supported scalar/timestamp CHECKs are enforced. LessonService and Stage D validate inspected replacement/rescheduling history; S3/Stage D validate affected reminder dependencies. Authentication/profile, planning-settings, and Academic Context APIs/Services are implemented. No planning defaults or conflict overrides are invented. Task deletion and scoped reminder cleanup, Stage A–D planning/session workflows and import confirmation are implemented. Fingerprint normalization/serialization and Import B2 generation are implemented. F1/F2 absolute/relative Reminder workflows and reconciliation are implemented; F3a in-app delivery is implemented; external delivery remains deferred. Ordered administrative account hard purge remains necessary for restrictive history graphs. Framework tables are preserved.
