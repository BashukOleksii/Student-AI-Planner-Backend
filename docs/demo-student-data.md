# Demo student data

The dedicated command creates one **new synthetic account** and its owned dataset. It never resets the database or changes existing accounts. No migrations, API routes, scheduled demo jobs, frontend changes or paid AI calls are added. Implementation began at `e7dc42f7cb923b9034c4454aa1b9cb605fd3234c` on `main`.

## Create locally

Prerequisites: a configured, migrated **local development MySQL** instance; `APP_ENV=local`; a single loopback connection (`localhost`, `127.0.0.1` or `::1`), without read/write splitting; valid application encryption key; dependencies installed. Keep the existing frontend/backend origins, Sanctum cookies and CSRF configuration. The frontend's existing `/api/auth/login` endpoint accepts the generated account normally.

First inspect the configured connection without writing:

```sh
php artisan demo:seed-student --inspect
```

Independently verify that the displayed database, MySQL server UUID, hostname and port identify the intended **development** instance. A database name, `APP_ENV` or a loopback address alone is not proof: a port can be forwarded to another server. Do not approve a production/staging instance or change a production environment to `local` to bypass this guard.

Then execute, replacing the identity placeholders with the verified inspection values:

```sh
DEMO_STUDENT_ENABLED=true \
DEMO_STUDENT_DATABASE='<verified-local-database>' \
DEMO_STUDENT_SERVER_UUID='<verified-local-server-uuid>' \
MAIL_MAILER=array QUEUE_CONNECTION=sync \
php artisan demo:seed-student
```

The exact creation command is **`php artisan demo:seed-student`** with the explicit approval configuration above. Alternatively set those non-password approval settings in your local uncommitted `.env`. If configuration is cached, clear it locally first with `php artisan config:clear`; inline environment changes cannot override cached configuration. Never commit `.env` credentials or demo database contents.

The command displays the actual connection identity and requires an interactive affirmative confirmation. Declining, `--no-interaction`, CI, nonlocal environments, wrong identity/driver/host, disabled approval, unsafe mail transport or asynchronous queue configuration all refuse creation. There is no `--force`, password argument, bulk reset or overwrite option. `--inspect` does not require enablement/approved identity or delivery configuration, but still requires a local MySQL loopback connection; CI is refused for the entire command.

On success, after the transaction commits, the terminal displays:

```text
DEMO STUDENT CREATED
Email: <unique generated address under example.test>
Password: <secure random password shown once>
Frontend: http://localhost:5173
```

The output also includes the user ID, dataset counts, exact command, recovery review/session IDs and dates for the working Saturday, room replacement and cancellation. **No actual password is documented here.** Do not redirect or upload credential output to logs, CI artifacts, screenshots or shared transcripts. Save it privately if you need to retain access. The backend stores only Laravel's standard password hash and does not expose the password through APIs. The command has no password-retrieval facility; use the ordinary authenticated password-management flow if needed.

## Generated data

Dates are relative to the captured execution date in `Europe/Kyiv`, with persisted event instants in UTC. The semester spans the current week minus four weeks through twelve future weeks. Every run uses a new ULID-based `demo.student.…@example.test` address. A uniqueness collision fails atomically instead of overwriting a user.

| Data | Contents |
|---|---|
| Profile | Олександр Тестовий, Europe/Kyiv, new synthetic email and random 32-character password |
| Academic context | One explicitly demonstration-labelled institution, one academic period, one semester/group, eight Ukrainian subjects and eight owned teachers with one-teacher subject associations |
| Semester | Fifteen weekday rules with morning/afternoon classes, rooms, lecture/practical/laboratory types and an alternating Tuesday rule |
| Exceptions | Next week's working Saturday copies Tuesday; next Monday freezes a room replacement; the following Monday cancels one occurrence while retaining other lessons |
| Independent academic lesson | A consultation added **after** initial planning, conflicting with the first future study session as an intentional recovery scenario |
| Tasks | 24 Ukrainian assignments across appropriate subjects; six completed, two explicitly missed, overdue pending work, close deadlines, varied priorities/estimates, long tasks and one unknown estimate |
| Subtasks | 15 meaningful steps on five tasks; five independently completed, ten pending; parents remain independently pending/missed |
| Availability | Weekdays 18:00–21:00; weekends 10:00–13:00 and 15:00–20:00; 30–60-minute sessions, 15-minute breaks, 180-minute daily and 900-minute weekly caps |
| Study history | Fourteen historical sessions (12 completed, two missed), plus one retired/rescheduled source and its completed successor; known synthetic actual minutes and genuinely null actual values |
| Future plan | Real deterministic preview/confirmed initial allocation for the REST API assignment, normally two planned sessions; only the newly added academic consultation deliberately conflicts |
| Recovery | One genuine pending `replan` suggestion, generated from current owned state by the existing detector; never confirmed by the generator |
| Reminders | Eight: five scheduled, one cancelled and two delivered to the local database inbox; task/session/subtask and relative reminders included |
| Inbox | Two actual delivery receipts, one marked read. Past-due synthetic reminder sources are delivered at generation time; delivery timestamps are not falsely backdated |
| AI | Two conversations/four clearly labelled synthetic user/assistant sample messages, no provider call, Tool receipt, pending AI action or claimed business mutation |

The expected study-session total is normally 18 (16 historical records plus two planned). The command reports actual validated counts. Unknown actual duration remains null. Known actual durations are explicit fictional fixture values, never inferred from a session's outcome. Historical source creation/completion dates are synthetic and backdated for useful progress views. No aggregate statistics are inserted; existing statistics/trend/comparison APIs calculate them from these sources.

## Recovery and manual inspection

1. Sign in at `http://localhost:5173` using the displayed credentials. Ensure the separate frontend points to this backend. Real browser behavior must be checked manually; automated checks validate the standard Laravel credentials/guard.
2. Open calendar today/date/week, then inspect the printed exception dates. Identify semester virtual occurrences versus the numeric independent consultation. Academic reads never include self-study sessions.
3. Open Tasks/Subtasks. Compare completed, overdue, explicitly missed, long, high-priority and unknown-estimate work. Completing a subtask does not automatically complete its parent.
4. Inspect availability and create a **preview** for an unallocated task. There is deliberately spare availability and many assignments without sessions. Review insufficient-time/deadline warnings instead of assuming everything fits.
5. Open Recovery. The consultation was added after planning; the original planned session is still present. Inspect the real replacement review, partial-allocation warnings and any required explicit consents. Only explicit confirmation may change that plan and cancel dependent scheduled reminders.
6. Reviews expire after at most 15 minutes and may become stale if a scheduler or source edit intervenes. Use the existing explicit **Refresh** operation to obtain a new review; refresh does not apply it. The normal detector is `php artisan learning:detect-recovery --user=<printed-user-id> --limit=1 --days=7` (subject to its existing environment/database operational safeguards). Do not run an unbounded global dispatcher merely to refresh the demo.
7. Inspect reminders and read/unread inbox entries. These are real database delivery records; no email/push was sent. Cancelled reminders and delivered history remain retained.
8. Compare statistics for the last 14 days and a previous period. Examine scheduled versus known actual time, unknown actual counts, overdue work, subject workload and allocation warnings.
9. Open the labelled AI sample conversations, then start a fresh real conversation if you have separately configured an approved provider. Normal real AI usage may incur provider charges; **the generator makes no such request**. No sample conversation is a successful Tool execution.

The existing recovery policy blocks actionable automatic proposals whenever an elapsed/in-progress planned session has an unknown outcome. Therefore this single account intentionally starts with an actionable calendar-conflict review **without** preloaded elapsed undecided sessions. Unknown *actual duration* is demonstrated on completed sessions. To demonstrate outcome clarification, leave an upcoming planned session undecided after its end, run the ordinary owner-scoped detector, then explicitly complete/miss it through the normal UI. Do not reinterpret it as missed automatically. Manual academic/availability/deadline edits can trigger subsequent real recovery after the initial review is resolved.

## Atomicity, reruns and limitations

All owned creation, local inbox delivery, genuine recovery detection and validation occur in one database transaction, with a new owner lock. Existing internal reminder mutation and planning confirmation operations are reused. Two guarded internal entry points reuse the unchanged recovery/delivery implementations for atomic composition; public outermost-transaction guards remain intact. There is no second planner, notification engine or approval system.

Synthetic historical sessions are bootstrapped through the existing session-attribute and lifecycle/chain rules with explicit historical clocks. Old reminder sources are prepared as owned fixtures and passed through the real database delivery Service. Services requiring current/future interactive input are not tricked by changing global application time. Only this newly created account receives these synthetic historical records.

Unexpected failure rolls back the new user and all its records; credentials are printed only after successful commit. Error output uses fixed diagnostics, with no raw SQL, bindings, private tokens or password traces. The command does not log credentials or retry uncertain writes.

Rerunning creates another unique complete demo account; it never replaces, updates or deletes earlier demo/real users. There is intentionally **no cleanup command**. Email resemblance does not authorize deletion. Use only the project's separately authorized account/purge procedures if cleanup is later needed; do not delete matching email patterns or reset the database.

This is not an Excel import fixture: manual semester records have no fabricated import provenance. Existing XLSX preview/import can be tried separately with an appropriate supported workbook. Ranked optimal plans, external notification delivery, real browser integration and live model quality are not asserted by seeding.

## Verification and local execution status

Tests use `scripts/testing/phpunit-disposable-mysql.sh`, which initializes a private fresh MySQL instance, verifies its datadir and empty schema and destroys only that test instance afterward. No valuable local database is used for automated seeding. Tests simulate the explicitly approved local environment only after the existing testing/MySQL guard runs. Console credentials are captured in memory, asserted without echoing the buffer and never written to test artifacts.

Coverage: local/production/CI refusal, exact database/server approval, confirmation/noninteractive refusal, successful credential hashing/authentication, owner isolation (including unrelated due reminders), repeatability, DST/week/date boundaries, academic associations, task/session lifecycles, real recovery, inbox delivery, statistics, atomic rollback after late real recovery/delivery, and internal transaction guards.

Executed on PHP 8.5.11 / MySQL 8.4.10 with fresh disposable servers, sequential database suites:

| Check | Passed tests | Assertions |
|---|---:|---:|
| Generator plus recovery/reminder regressions | 269 | 2255 |
| Final generator rerun with overlap, delivery replay and exact statistics checks | 18 | 774 |
| Unit suite | 2820 | 9018 |
| Smoke suite | 103 | 1428 |

No failures, errors, skips or risky tests remained in these final runs. Pint and changed-file PHP syntax checks passed. The complete offline suite, paid providers, real browser and production operation were not run for this task. The generator's login hash and standard Laravel authentication guard were verified; the separate Vue login screen remains a manual check.

```sh
scripts/testing/phpunit-disposable-mysql.sh --filter 'DemoStudentCommandTest|AutomaticRecovery|AutomaticLearningRecoveryTest|RecoveryReadToolsTest|ReminderDeliveryTest|ReminderDeliveryConcurrencyTest|ReminderRulesTest|ReminderDeliveryRulesTest'
scripts/testing/phpunit-disposable-mysql.sh --filter DemoStudentCommandTest
vendor/bin/phpunit --testsuite Unit
scripts/testing/phpunit-disposable-mysql.sh --configuration phpunit-smoke.xml
vendor/bin/pint --test
```

The actual developer configuration was checked with `php artisan demo:seed-student --no-interaction`; it refused with **“Demo generation has not been explicitly enabled.”** No persistent demonstration account or credentials were created. An explicitly approved local database is not configured in this workspace, so execute the creation command above yourself after verifying and approving the actual identity. Successful test-only accounts were confined to disposable MySQL and removed with it.
