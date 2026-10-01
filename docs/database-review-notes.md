# Existing Database Draft — Review Notes for Stage 02

Status: review only. Do not apply schema changes during Stage 01.

The provided SQL/ER draft is useful as an early inventory of concepts, but it should not be treated as the final Laravel/MySQL schema.

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

## 2. Issues that must be resolved before migrations

### 2.1 One column has two incompatible foreign-key targets

`study_sessions.task_id` is defined as referencing both `tasks.id` and `sub_tasks.id`.

A normal relational foreign-key column cannot safely mean "either table A or table B" with two foreign keys. Stage 02 must choose a valid design, such as:

- explicit `task_id` and nullable `sub_task_id` with invariants;
- a polymorphic target if justified;
- or planning sessions only against one canonical schedulable-work entity.

Do not reproduce the current dual-FK design in Laravel migrations.

### 2.2 Reminder polymorphism is expressed as incompatible foreign keys

The draft has `remindable_type` + `remindable_id`, which resembles a Laravel polymorphic relation, but also adds foreign keys from the same `remindable_id` to several tables.

Those approaches conflict. A polymorphic `morphTo` relation normally cannot have a conventional database FK to several target tables. Stage 02 must choose one strategy.

### 2.3 Naming is inconsistent with Laravel conventions

Examples:

- `tasks.users_id` versus `lessons.user_id`;
- `password_hash` versus Laravel's normal authentication expectation of `password` unless customized;
- `education_institutions` used as a foreign-key column name rather than a singular `_id` convention;
- spelling such as `subtituted_lessons_id`.

The final schema should prefer standard Laravel naming unless there is a documented reason not to.

### 2.4 Named SQL types appear non-portable for MySQL

The draft uses types such as `class_type` / diagram enum types. MySQL normally needs a concrete column representation (for example string/enum/tiny integer), and Laravel migrations must express the actual type explicitly.

Stage 02 should decide how PHP enums map to MySQL columns.

### 2.5 Subject/teacher ownership is unclear

`subjects` and `teachers` point to an education institution but not to a user. The requirements need per-user privacy, while it is not yet clear whether subjects/teachers are:

- global institution reference data;
- user-owned custom data;
- or shared templates copied into a user's context.

This must be resolved before authorization and foreign keys are designed.

### 2.6 Academic period / semester is missing

Requirements mention re-importing a schedule for a new semester, but the draft has no explicit period/semester boundary. Stage 02 should decide whether dates alone are sufficient or whether an `academic_periods` concept improves import and history behavior.

### 2.7 Planning preferences are opaque JSON

`users.planning_settings` can be convenient, but some settings participate directly in deterministic planning queries/rules. Stage 02 should decide which settings deserve typed columns/rows and which genuinely belong in JSON.

Do not default to JSON merely to avoid modeling decisions.

### 2.8 Import lifecycle is not represented

The requirements require preview, validation errors, and re-import behavior. The current schema has no import batch/history concept. It may be acceptable to keep previews ephemeral, but Stage 02 should make that decision explicitly.

### 2.9 Completion history may be insufficient for some statistics

The requirements include "completed on time vs late" and planned-vs-actual analysis. A current status alone may not preserve when completion occurred. Stage 02 should decide whether timestamps such as completion time are required.

### 2.10 Soft-delete behavior needs domain justification

Several draft tables use `deleted_at`. Soft deletes should be introduced only where historical reporting, recovery, or references need them. Stage 02 must specify what deletion means for planning and reports.

## 3. Index notes

The draft correctly anticipates indexes around:

- user + lesson date/time;
- user + task status/deadline/priority;
- user + study-session time/status;
- reminder send state + trigger time;
- conversation/message ordering.

Final indexes should be derived from actual query patterns after the final columns and ownership rules are chosen.

## 4. Stage 02 objective

Use `architecture.md`, `domain-model.md`, `business-rules.md`, this review, the existing SQL, and the ER image to produce a new normalized schema rather than patching the current draft mechanically.
