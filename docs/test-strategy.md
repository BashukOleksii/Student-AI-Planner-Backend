# Backend test strategy

## Routine smoke suite

Run the focused cross-system regression suite against the same isolated MySQL
schema as the full feature tests:

```sh
vendor/bin/phpunit --configuration phpunit-smoke.xml
```

`phpunit-smoke.xml` selects methods tagged `smoke` from the existing Feature test
classes. The test methods remain in the normal `Feature` suite, and every other
Unit and Feature test stays available through `vendor/bin/phpunit`. The group
covers registration/authentication, cross-user task denial, semester schedule
read, confirmed Excel import, effective free-time ownership, confirmed planning
with stale review and rollback, replanning with stale review and rollback, AI
proposal/confirmation including ownership and atomic failure, reminder ownership
and rollback, and statistics owner isolation.

Only end-to-end checks belong in this group. Detailed invalid-input matrices,
all status permutations, exhaustive conflict combinations, provider-wire variants,
lock interleavings, migration compatibility and pure algorithm edge cases remain
in the full suite. The selected 18 test methods expand to 91 PHPUnit cases. On the current local
MySQL test database, the measured command completed in **26.12 seconds**:
91 passed, 1,123 assertions, zero failures or errors. The full configuration still
discovers 2,791 Unit and 3,900 Feature cases (6,691 total). The smoke suite
supplements the full CI suite; it does not replace or gate it.

## Full suite and CI

`phpunit.xml` still exposes `Unit` and `Feature`; run both with
`vendor/bin/phpunit`. The GitHub Actions workflow continues to run the complete
offline MySQL suite and the separate Gemini action matrix. Deployment still
requires a successful verify job, a push to the repository's `main`, and the
existing explicit enable variable. This task changes neither workflow commands
nor deployment conditions.

## Cleanup proposal for a later task

Do not delete tests as part of this strategy change. First collect per-test runtime
from JUnit output over several CI runs and compare coverage by behavior, not by
file size. Then consider these narrow cleanups:

1. Remove `tests/Unit/ExampleTest.php` and `tests/Feature/ExampleTest.php` if they
   still contain only Laravel scaffold assertions. They exercise no planner,
   persistence or API contract; removing them would drop two cases with no product
   coverage loss.
2. Audit the repeated authentication-before-validation test lists in endpoint
   suites. Consolidate identical route/method checks into one table-driven security
   test while retaining one assertion per sensitive endpoint and preserving
   cross-user and ownership tests. The benefit is less duplicated setup and less
   fixture drift; no endpoint check should disappear.
3. Review provider contract snapshots and action matrices only after comparing
   behavior coverage. OpenAI and Gemini wire contracts, per-action authorization,
   stale rejection, replay and rollback each protect separate boundaries and must
   remain covered. Avoid collapsing the matrix if it loses provider-specific
   omission/null semantics or lifecycle coverage.
4. Keep all migration rollback/refusal, independent-process concurrency and
   deterministic planner edge tests in the full suite. Their runtime is justified
   by database integrity, locking and scheduling risk; the smoke suite is the
   appropriate fast path for routine edits.

The complete suite remains the release check. A passing smoke run is a fast signal,
not evidence that migrations, race conditions or all domain edge cases passed.
