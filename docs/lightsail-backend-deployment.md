# Lightsail Backend deployment — Stage 20B.16B

Implementation is initially disabled. Nothing in this stage provisions AWS, connects
by SSH, changes production configuration/data, or enables deployment. Review this
runbook and the scripts before doing the one-time setup below. Frontend deployment
is independent and unchanged.

## Design and boundaries

`backend-verification.yml` retains PHP 8.4, disposable MySQL 8.4, migration checks,
Pint, the complete offline PHPUnit suite and Gemini regressions. It also runs the
offline deployment fixtures. Its `deploy` job requires `verify` success, a `push`
to this repository's `main`, and repository variable
`LIGHTSAIL_BACKEND_DEPLOY_ENABLED=true`. PRs and manual workflow runs cannot deploy.
An unset flag is disabled. The job uses Environment `production` and checks out
`github.sha`, the same SHA verified in that run. Its runner script repeats these
guards before touching SSH credentials.

GitHub concurrency serializes Backend deployments without cancelling a running
one; GitHub may replace a pending job with a newer pending job. The server has a
separate nonblocking `flock`. An older verified SHA can deploy only while it is on
origin/main and ahead of the current server checkout; it cannot downgrade an
already newer checkout. Branch protection should prohibit main force pushes and
require CI/review for workflow and deployment-script changes.

The fixed checkout stays `/srv/student-ai-planner/backend`. The reviewed script is
installed separately at `/usr/local/libexec/student-planner-backend-deploy`, owned
by root but **executed as ubuntu**. CI sends only the tested SHA. Script changes
need a reviewed manual reinstall; CI cannot overwrite its own privileged helper.
No release symlinks or Nginx/systemd paths change. Atomic release directories with
a shared writable directory and an independently switchable release are a future
architecture improvement, requiring a separately reviewed migration.

Preflight checks repository URL, branch, clean tracked/untracked state, commit
identity, ancestry, migration differences, runtime paths, PHP, permissions and
existing service health. Fetch is noninteractive; it updates origin/main without
forcing a rewritten ref. Fast-forward merge uses `--no-overwrite-ignore` to
protect ignored server files as well as explicitly rejecting tracked runtime files.
No reset, clean, stash, key generation, migration or database test runs.

Once preflight passes, the script creates an interruption marker, requires file
maintenance mode and standard bootstrap cache paths, pre-renders Laravel's 503 view, and saves the maintenance gate
outside the checkout. It stops the scheduler timer and its service, drains/stops
the queue, and stops PHP-FPM before changing files. Public Nginx may return **502**
while FPM is stopped; this is a short Backend outage, not a zero-downtime deploy.
The separately served Frontend remains available. Stopping FPM prevents an old
request from reading a mixture of old/new files during the in-place update.

Production dependencies come from `composer.lock`, without plugins or scripts;
platform checks run before/after installation. Package discovery runs explicitly,
then configuration, route and view caches are rebuilt. The five generated standard
bootstrap caches (config, services, packages, routes-v7 and events) are removed
before the first new Artisan bootstrap, since old manifests can reference removed
providers. `clear-compiled` and explicit discovery then rebuild the current state. No `optimize:clear` or shared
application cache flush is used (database cache contains live application state).
Fresh FPM processes replace old OPcache. Queue workers are stopped gracefully and
started fresh through systemd; no database-backed `queue:restart` signal is needed.
The scheduler timer restarts after HTTP checks. `schedule:run` is never invoked by
the deployment, so it does not manually dispatch production reminders.

## GitHub setup (manual, after review)

Create Environment **production**, restrict deployment branches to `main`, and
choose reviewer protection according to repository policy. Required reviewers
make each deployment wait for approval; omit that protection if fully automatic
operation is wanted. Keep code review and protected-main verification.

| Scope | Name | Value |
| --- | --- | --- |
| Repository variable | `LIGHTSAIL_BACKEND_DEPLOY_ENABLED` | Initially `false` or absent; only later explicitly set `true` |
| production Environment secret | `LIGHTSAIL_BACKEND_SSH_PRIVATE_KEY` | New dedicated Backend Ed25519 private key, no passphrase for unattended SSH |
| production Environment secret | `LIGHTSAIL_BACKEND_KNOWN_HOSTS` | Independently verified full host-key entry for `63.184.35.171` |

Do not put credentials in repository variables, source, screenshots or logs. Do
not reuse the Frontend key. Host, user and paths are fixed to the reviewed target.

On a trusted administrator machine generate a dedicated key (store it securely):

```sh
ssh-keygen -t ed25519 -f student-planner-backend-actions -C backend-actions
```

Choose an empty passphrase for this dedicated CI key; paste the private key into
the Environment secret through GitHub's secret UI, and install only its public key
on the server. Do not print the private key in an Actions step.

Obtain the server host public key and fingerprint from an independently trusted
Lightsail console/admin channel, for example:

```sh
sudo ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
awk '{print "63.184.35.171 " $1 " " $2}' /etc/ssh/ssh_host_ed25519_key.pub
```

Verify that fingerprint independently before saving the resulting known_hosts
line. `ssh-keyscan` alone is not verification. On host-key rotation, disable the
flag, independently verify the replacement, update the secret, then re-enable.
The runner uses strict checking, a dedicated known_hosts file, no ambient SSH
configuration/agent, no password fallback, and no forwarding. A wrong host key
fails SSH before the remote command executes.

## Server prerequisites and least privilege (manual only)

Use a separate trusted administrator session. Inspect current units and permissions
before changing anything. **These setup commands are documentation, not CI steps.**
Do not reinstall PHP, modify Nginx or rewrite existing units for this stage.

Verify Ubuntu 24.04 tools: Bash, Git, util-linux/flock, curl with CA certificates,
Python 3, Composer 2 and both default `php` and `php8.4` at **8.4.1+** (locked Symfony
requires this patch minimum). Confirm CLI and FPM have matching production extensions,
including pdo_mysql, mbstring, xml/dom, fileinfo, gd, zip, intl and bcmath. The
`php8.4-fpm` service name must resolve to the intended runtime. Ensure Composer is a
trusted installation and no global plugins/hooks or Git hooks alter deployment.
The script requires standard Laravel bootstrap cache paths; custom APP_*_CACHE
overrides are refused before code mutation and need a separately reviewed plan.

Inspect (do not log environment secrets):

```sh
systemctl cat php8.4-fpm.service student-ai-planner-queue.service
systemctl cat student-ai-planner-scheduler.timer student-ai-planner-scheduler.service
systemctl show php8.4-fpm.service student-ai-planner-queue.service -p KillSignal -p TimeoutStopUSec -p UMask
```

Confirm the timer activates `student-ai-planner-scheduler.service`, whose
`schedule:run` uses this checkout. Review queue stop semantics: Laravel workers
need SIGTERM and a sufficient existing stop timeout to finish their longest job;
FPM must drain existing requests. Do not enable until existing unit behavior is
safe. Also verify runtime file creation keeps storage and bootstrap/cache private
and shared with www-data (review existing UMask/ACL behavior for FPM, queue and
scheduler). If adjustments are necessary, request a separate reviewed operator change;
this task does not edit units. Deployment audits the timer's active state, not
actual delivery of a production reminder. Operators should also inspect recent
scheduler service success and timer next/last firing without exposing user data.

The checkout must be a clean `main`, owned for deployment by `ubuntu`, with the
intended origin URL. Establish the actual deployed baseline and applied migration
status manually before first enablement; do not assume a clean Git tree proves
its database is current. HTTPS fetch of a public repo needs no GitHub credential.
For a private repo use a separate read-only repository deploy key on the server,
with independently pinned GitHub host keys and strict SSH checking; never forward
the CI-to-Lightsail key/agent or embed a token in the origin URL.

Install reviewed copies from the reviewed commit, using an administrator:

```sh
sudo install -d -o root -g root -m 755 /usr/local/libexec
sudo install -o root -g root -m 755 scripts/deploy/deploy-backend.sh /usr/local/libexec/student-planner-backend-deploy
sudo install -o root -g root -m 755 scripts/deploy/student-planner-backend-control /usr/local/sbin/student-planner-backend-control
sudo install -o root -g root -m 755 scripts/deploy/check-permissions.py /usr/local/libexec/student-planner-backend-permissions.py
sudo install -o root -g root -m 755 scripts/deploy/ssh-dispatch /usr/local/libexec/student-planner-backend-ssh
sudo install -d -o ubuntu -g ubuntu -m 700 /srv/student-ai-planner/backend-deploy
```

Verify checksums against the reviewed source; never run a checkout-writable script
as root. State is private and outside the tree: lock, interruption marker, saved
503 gate and last successful SHA. `last-successful-sha` is historical evidence,
not proof of current health after a failed or interrupted attempt.

Add the dedicated public key to ubuntu's authorized_keys with these options:

```text
restrict,command="/usr/local/libexec/student-planner-backend-ssh" ssh-ed25519 PUBLIC_KEY_HERE backend-actions
```

`restrict` disables forwarding, PTY and user rc; the root-owned dispatcher accepts
only the fixed deploy command and a 40-character lowercase SHA. Preserve other
administrator keys. The key still grants application deployment capability:
reviewed main code runs as ubuntu, so protect main and the Environment secrets.

Using `visudo -f /etc/sudoers.d/student-planner-backend`, grant only:

```sudoers
Defaults:ubuntu env_reset
ubuntu ALL=(root) NOPASSWD: /usr/local/sbin/student-planner-backend-control stop-fpm, /usr/local/sbin/student-planner-backend-control start-fpm, /usr/local/sbin/student-planner-backend-control stop-queue, /usr/local/sbin/student-planner-backend-control start-queue, /usr/local/sbin/student-planner-backend-control stop-scheduler, /usr/local/sbin/student-planner-backend-control start-scheduler, /usr/local/sbin/student-planner-backend-control health, /usr/local/sbin/student-planner-backend-control audit
```

Check syntax with `visudo -cf`. The fixed root-owned helper permits only these
service operations and a read-only audit as www-data; it never runs Git, Composer
or Artisan as root. **Check effective `sudo -l -U ubuntu`: a broad existing ubuntu
NOPASSWD/ALL rule would defeat least privilege.** An administrator must reconcile
such grants before enablement, preserving an independent administrative recovery
channel. Do not remove broad grants blindly or lock yourself out.

## Permissions and the umask regression

The deploy script sets **umask 022** before Git/Composer. Laravel cache generation
uses **007**, then explicitly restricts `bootstrap/cache/config.php` to **640**.
The script validates `.env` is a regular file with **640 ubuntu:www-data**, and
checks its content hash and metadata again at the end without printing them.
It never writes `.env` or regenerates APP_KEY.

Confirm ubuntu is in group www-data in the new SSH session. Code directories need
traversal and code/vendor files need read access for www-data. Do not chown the
repository to www-data. Writable runtime directories must have ubuntu/www-data
shared access, setgid directories and no access for unrelated users. A typical
reviewed, narrowly scoped repair is:

```sh
cd /srv/student-ai-planner/backend
sudo chown ubuntu:www-data .env
sudo chmod 640 .env
sudo chgrp -R www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2770 {} +
sudo find storage bootstrap/cache -type f -exec chmod 660 {} +
sudo chmod 640 bootstrap/cache/config.php  # if present
```

Do this only after inspecting existing ACLs, symlinks and owners; preserve uploaded
files and working ownership. No recursive 777 or repository-wide ownership change.
For pre-existing mode-600 code, repair only reviewed tracked application files and
required vendor files: directories 755; files readable (644, or 755 for executable
files). Never apply repository-wide recursive read permissions: `.env`, runtime
files and cached config contain secrets. New files receive safe permissions from
the explicit umasks; existing inaccessible files cause refusal rather than silent
broad permission changes.

The read-only audit actually runs as www-data and checks all required source/vendor
files, directory traversal, writable runtime directories and sensitive runtime
permissions. Unexpected code/runtime symlinks are rejected, except the existing
`public/storage` upload link. Resolve a different existing layout deliberately;
do not silently follow or rewrite it. Both initial and final audits must pass:

```sh
sudo -n /usr/local/sbin/student-planner-backend-control audit
sudo -n /usr/local/sbin/student-planner-backend-control health
```

These read-only checks are suitable before enablement; do not invoke the deployment
script as a setup check, since that performs a real deployment.

## Migration gate and approved schema changes

Automatic deployment compares **the server HEAD to the tested SHA**, not just the
last commit, for any differences under `database/migrations` (add/modify/delete/
rename all block). There is no automatic migration or override flag. No database
backup is required for ordinary code-only deploys. Existing database cache/session
access by Laravel and the unauthenticated CSRF smoke request are normal runtime
activity; no production tests, resets, seeders or user creation take place.

For a schema change: disable the deploy flag; review the migration and application
compatibility; record server HEAD and target SHA; recommend and verify a restorable
backup; test the upgrade against **disposable MySQL 8.0 matching production's exact
patch, SQL mode, charset/collation and isolation**, including a representative
sanitized existing schema. CI's MySQL 8.4 success does not establish MySQL 8.0
compatibility. Review CHECK enforcement/DDL/locking and forward-only migrations
noted in `docs/database-review-notes.md`; never edit already-applied migrations.

An authorized operator then takes the same server lock, establishes maintenance,
stops background services/FPM, fetches and fast-forwards only to the reviewed tested
SHA, installs locked dependencies, and applies **only the explicitly approved
forward migration(s)** with `php8.4 artisan migrate --force` after checking the
pending list matches the approval. Rebuild caches, audit permissions, start FPM,
leave maintenance, run the exact HTTP checks below and restore queue/timer. Record
schema/application versions and release the lock. Do not run automatic rollback:
some repository migrations intentionally refuse `down`. On failure use a reviewed
forward correction; involve the operator who approved the schema change. Only
clear a recovery marker and re-enable automatic deployment after this manual
release is verified. The next ordinary code-only push can then fast-forward from
that manually reviewed baseline.

## Failure, interruption and controlled recovery

Every failure emits a fixed phase, requested SHA, actual checkout SHA, previous SHA
and smoke status. Tool exception output is suppressed because it can contain SQL
bindings or credentials. SSH authentication/host failures occur before the remote
script can report a checkout: Actions reports health/deployment unconfirmed.
No error claims the old version is active after code mutation.

Before mutation, recoverable errors attempt to leave maintenance and restore the
previous services. After the Git merge begins (including partial Composer install,
cache failure, permission failure, service failure or failed HTTP smoke), the trap
restores the saved pre-rendered gate without depending on Laravel/vendor, attempts
to stop FPM/queue/scheduler, retains the marker and exits nonzero. If containment
itself fails, it emits a critical diagnostic and requires immediate operator
inspection; it never asserts that shutdown succeeded without verification. It deliberately does not
call `artisan up` on a potentially partial installation. Maintenance after such a
failure is an intentional recovery requirement, not a claimed healthy release.

SIGTERM/HUP/INT run the same trap. Power loss/SIGKILL may prevent traps; the durable
marker blocks another automatic attempt. CI cancellation/SSH loss can leave a
remote command still running with the lock: do not assume a cancelled job stopped
all remote work. A power loss immediately after `artisan up` can leave HTTP exposed
before smoke completion, but all files/caches/audits are completed before `up`;
the marker still requires manual health verification. In-place deployment cannot
make a transactional availability/health guarantee across power failure.

Recovery is a manual operator operation, never an automatic destructive reset:

1. Set the repository flag false. From a trusted admin session acquire the same
   lock: `exec 9>/srv/student-ai-planner/backend-deploy/lock; flock -n 9`.
   If busy, inspect the running deploy; do not delete the lock file or start a
   second deployment. Record `git rev-parse HEAD`, clean-tree status, marker and
   previous/requested SHAs. Do not print config/cache/env or session data.
2. Restore the saved `down` and `maintenance.php` to `storage/framework` if needed;
   keep runtime modes private and readable by www-data. Stop timer/service, queue
   and FPM with the helper. If saved files are unavailable, keep FPM stopped until
   the application can generate a valid maintenance gate. Never blindly run `up`.
3. Prefer completing the exact verified SHA: with `umask 022`, repair the diagnosed
   external issue and repeat the locked Composer install/platform checks. If Git
   stopped before finishing, inspect changes/index and complete only a verified
   safe fast-forward; preserve local/server files and escalate conflicts manually.
4. For an application rollback, identify a previously verified compatible commit.
   Prefer a reviewed **forward revert commit on main**, with CI, then manually
   fast-forward to it under the lock. Reinstall dependencies from its lock file.
   Do not reset/clean the live checkout or downgrade migrations. If rollback code
   cannot safely use the current schema, a forward fix is required. Automatic
   deployment remains disabled during recovery.
5. With services stopped, remove only the generated standard files
   `bootstrap/cache/{config,services,packages,routes-v7,events}.php` before the
   first new Artisan bootstrap. Under umask 007, repeat `config:clear`, `clear-compiled`, `package:discover`,
   `config:cache`, `route:cache`, `view:cache`; restrict config.php to 640. Verify
   unchanged `.env`, permission audit and actual SHA. Start FPM, use `artisan up`
   only with a consistent installation, and run HTTP checks. If checks fail,
   restore maintenance and stop FPM again. Start queue and timer only once HTTP
   passes; check all services and scheduler's recent/next run.
6. Record the actual successful SHA in `last-successful-sha`; remove only the
   diagnosed `in-progress` marker after complete verification; release the lock.
   Re-enable the flag only by explicit operator decision. Keep recovery evidence
   privately; never upload secrets as Actions artifacts.

## Exact deployment verification

Successful logs must show the tested/deployed/checkout SHA match, three HTTP status
checks and active FPM/queue/timer. The Actions step summary repeats success and
smoke results; failure/cancellation is explicitly unconfirmed. GitHub Environment
records the deployment outcome. A historical success is not a live health monitor.

From a trusted server session, these commands perform the same bounded,
unauthenticated, nondestructive checks without logging bodies, headers or cookies:

```sh
git -C /srv/student-ai-planner/backend rev-parse HEAD
curl --disable --noproxy '*' --silent --output /dev/null --write-out '%{http_code}\n' --connect-timeout 5 --max-time 20 -H 'Accept: application/json' http://127.0.0.1:8080/up
curl --disable --noproxy '*' --silent --output /dev/null --write-out '%{http_code}\n' --connect-timeout 5 --max-time 20 -H 'Accept: application/json' https://student-ai-planner.duckdns.org/api/profile
curl --disable --noproxy '*' --silent --output /dev/null --write-out '%{http_code}\n' --connect-timeout 5 --max-time 20 -H 'Accept: application/json' https://student-ai-planner.duckdns.org/sanctum/csrf-cookie
sudo -n /usr/local/sbin/student-planner-backend-control health
sudo -n /usr/local/sbin/student-planner-backend-control audit
systemctl list-timers student-ai-planner-scheduler.timer --no-pager
```

Expect **200, 401, 204**, respectively, and exit 0 for both helper checks. Do not
follow redirects, supply login cookies, create test accounts or run PHPUnit on
production. Inspect scheduler service result locally; never publish raw logs with
personal data. After review/setup, explicit enablement followed by a new verified
main push is the first real deployment; no workflow_dispatch bypass is provided.

Offline checks for this stage:

```sh
python3 -m unittest discover -s tests/Deployment -v
bash -n .github/scripts/deploy-lightsail.sh scripts/deploy/deploy-backend.sh scripts/deploy/student-planner-backend-control scripts/deploy/ssh-dispatch
vendor/bin/pint --test
vendor/bin/phpunit --testsuite Unit
```

Fixtures use temporary repositories and mocked SSH/PHP/Composer/services/curl.
They never connect to Lightsail or use production credentials/database.

Implementation references: [GitHub deployment controls](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments),
[Laravel deployment](https://laravel.com/framework/docs/deployment),
[Composer CLI](https://getcomposer.org/doc/03-cli.md),
[Git merge safety](https://git-scm.com/docs/git-merge),
[OpenSSH options](https://man.openbsd.org/ssh_config).
