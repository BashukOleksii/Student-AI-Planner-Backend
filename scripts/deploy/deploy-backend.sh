#!/usr/bin/env bash
# Reviewed root-owned installation, executed as ubuntu with the exact CI-verified SHA.
set -Eeuo pipefail
umask 022
export GIT_TERMINAL_PROMPT=0
export COMPOSER_NO_INTERACTION=1
export COMPOSER_ALLOW_SUPERUSER=0
readonly root=/srv/student-ai-planner/backend
readonly state=/srv/student-ai-planner/backend-deploy
readonly control=/usr/local/sbin/student-planner-backend-control
readonly repository=BashukOleksii/Student-AI-Planner-Backend
requested=${1:-}
phase=preflight
mutated=0
maintenance=0
started=0
smoke=not-run
previous=unknown

say() { printf '%s\n' "$*"; }
fail() { say "REFUSED: $*" >&2; exit 1; }
# Suppress tool output: Artisan/Composer exceptions can contain credentials or SQL bindings.
run() { "$@" >/dev/null 2>&1; }
svc() { run sudo -n "$control" "$1"; }
restore_gate() {
    # Independent of vendor/bootstrap; saved before any in-place changes.
    cp "$state/down" storage/framework/down &&
        cp "$state/maintenance.php" storage/framework/maintenance.php &&
        chmod 660 storage/framework/down storage/framework/maintenance.php
}
finish() {
    result=$?
    trap - EXIT HUP INT TERM
    if (( result != 0 )); then
        if (( started )); then
            if (( mutated )); then
                gate=restored queue_stop=ok scheduler_stop=ok fpm_stop=ok
                restore_gate || gate=failed
                # Contain first, report afterwards, even if the SSH output channel is lost.
                svc stop-queue || queue_stop=failed
                svc stop-scheduler || scheduler_stop=failed
                svc stop-fpm || fpm_stop=failed
                if [[ $gate == failed || $queue_stop == failed || $scheduler_stop == failed || $fpm_stop == failed ]]; then
                    say "CRITICAL: containment gate=$gate queue-stop=$queue_stop scheduler-stop=$scheduler_stop fpm-stop=$fpm_stop"
                fi
                say 'RECOVERY REQUIRED: maintenance restoration and service shutdown attempted; verify state; no automatic rollback.'
            else
                # Only pre-mutation failures can safely reopen the previous installation.
                if (( maintenance )); then
                    run php8.4 artisan up || result=1
                fi
                if svc start-fpm && svc start-queue && svc start-scheduler &&
                    [[ ! -f storage/framework/down && ! -f storage/framework/maintenance.php ]]; then
                    rm -f "$state/in-progress"
                    say 'Previous installation restored before code mutation.'
                else
                    say 'RECOVERY REQUIRED: pre-mutation service restoration failed.'
                fi
            fi
        fi
        current=$(git rev-parse HEAD 2>/dev/null || printf unknown)
        say "DEPLOYMENT FAILURE requested=$requested checkout=$current previous=$previous phase=$phase smoke=$smoke healthy=unverified"
    fi
    exit "$result"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

[[ $# == 1 && $requested =~ ^[0-9a-f]{40}$ ]] || fail 'Expected one full tested commit SHA.'
[[ $(id -un) == ubuntu && $(id -u) != 0 ]] || fail 'Run as the non-root ubuntu deployment owner.'
cd "$root"
[[ -d $state && ! -L $state ]] || fail 'Missing deployment state directory.'
exec 9>"$state/lock"
flock -n 9 || fail 'Another Backend deployment holds the lock.'
[[ ! -e $state/in-progress ]] || fail 'Interrupted/failed deployment needs operator recovery.'
[[ $(git rev-parse --show-toplevel) == "$root" ]] || fail 'Unexpected checkout location.'
[[ $(git symbolic-ref --short HEAD) == main ]] || fail 'Server branch must be main.'
case $(git remote get-url origin) in
    "https://github.com/$repository.git"|"https://github.com/$repository"|"git@github.com:$repository.git") ;;
    *) fail 'Unexpected origin repository.' ;;
esac
checkout_status=$(git status --porcelain --untracked-files=all) || fail 'Could not inspect checkout.'
[[ -z $checkout_status ]] || fail 'Dirty checkout.'
[[ $(stat -c '%a:%G' bootstrap/cache) == 2770:www-data ]] || fail 'bootstrap/cache must be setgid 2770 with group www-data.'
# Ignored runtime files must never become tracked by a deployment.
run git fetch --no-tags origin refs/heads/main:refs/remotes/origin/main || fail 'Fetch failed.'
[[ $(git rev-parse "$requested^{commit}") == "$requested" ]] || fail 'SHA mismatch or missing commit.'
run git merge-base --is-ancestor "$requested" refs/remotes/origin/main || fail 'SHA is not on origin/main.'
previous=$(git rev-parse HEAD)
run git merge-base --is-ancestor "$previous" "$requested" || fail 'Checkout is ahead of or diverged from tested SHA.'
migration_changes=$(git diff --name-only "$previous" "$requested" -- database/migrations) || fail 'Could not inspect migration changes.'
[[ -z $migration_changes ]] || fail 'Migration changes require manual review.'
runtime_entries=$(git ls-tree -r --name-only "$requested" -- .env vendor storage bootstrap/cache | sed '/^storage\/.*\.gitignore$/d; /^bootstrap\/cache\/\.gitignore$/d') || fail 'Could not inspect runtime file protection.'
[[ -z $runtime_entries ]] || fail 'Commit tracks server-owned runtime files.'
# Record env metadata/content in memory only; never copy it to Actions or display it.
[[ -f .env && ! -L .env && $(stat -c '%a:%U:%G' .env) == 640:ubuntu:www-data ]] || fail 'Expected .env mode/owner 640 ubuntu:www-data.'
env_fingerprint=$(sha256sum .env)
[[ ! -e storage/framework/down && ! -e storage/framework/maintenance.php ]] || fail 'Existing maintenance mode requires operator review.'
run php8.4 -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' || fail 'PHP 8.4 required.'
run php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' || fail 'Composer default PHP must also be 8.4.'
run composer check-platform-reqs --no-dev --no-plugins || fail 'Current PHP platform check failed.'
svc audit || fail 'Initial www-data permission audit failed.'
svc health || fail 'Backend services are not initially active.'

phase=maintenance
printf 'previous=%s\nrequested=%s\n' "$previous" "$requested" > "$state/in-progress"
started=1
# File maintenance driver is required; the config inspection prints no config values.
run php8.4 -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (config("app.maintenance.driver") !== "file") exit(1); foreach (["getCachedConfigPath" => "config.php", "getCachedServicesPath" => "services.php", "getCachedPackagesPath" => "packages.php", "getCachedRoutesPath" => "routes-v7.php", "getCachedEventsPath" => "events.php"] as $method => $file) { if ($app->$method() !== getcwd()."/bootstrap/cache/".$file) exit(1); }' || fail 'File maintenance driver and standard bootstrap cache paths required.'
maintenance=1
(umask 007; run php8.4 artisan down --render=errors::503 --retry=60) || fail 'Maintenance bootstrap failed.'
[[ -s storage/framework/down && -s storage/framework/maintenance.php ]] || fail 'Maintenance files missing.'
cp storage/framework/down "$state/down"
cp storage/framework/maintenance.php "$state/maintenance.php"
svc stop-scheduler || fail 'Scheduler stop failed.'
svc stop-queue || fail 'Queue stop failed.'
svc stop-fpm || fail 'PHP-FPM stop failed.'

phase=git-update
# From this point on, any failure needs controlled recovery, including a partial merge/install.
mutated=1
run git -c core.sharedRepository=false merge --ff-only --no-edit --no-overwrite-ignore "$requested" || fail 'Fast-forward failed.'
[[ $(git rev-parse HEAD) == "$requested" ]] || fail 'Updated checkout SHA mismatch.'
phase=composer
# No plugins/scripts, root execution, updates, migrations or key generation.
run composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts --no-plugins || fail 'Locked dependency install failed.'
run composer check-platform-reqs --no-dev --no-plugins || fail 'Production platform requirements failed.'
phase=laravel-caches
# These are generated framework caches only. Old provider classes may no longer
# exist after install --no-dev; remove their manifests before booting Artisan.
run rm -f -- bootstrap/cache/config.php bootstrap/cache/services.php \
    bootstrap/cache/packages.php bootstrap/cache/routes-v7.php bootstrap/cache/events.php || fail 'Could not remove stale bootstrap caches.'
# The directory must be setgid ubuntu:www-data; sensitive config stays 640/660.
(
    umask 007
    run php8.4 artisan config:clear &&
    run php8.4 artisan clear-compiled &&
    run php8.4 artisan package:discover &&
    run php8.4 artisan config:cache &&
    run php8.4 artisan route:cache &&
    run php8.4 artisan view:cache
) || fail 'Laravel cache/bootstrap rebuild failed.'
[[ -f bootstrap/cache/config.php && ! -L bootstrap/cache/config.php ]] || fail 'Missing config cache.'
chmod 640 bootstrap/cache/config.php
[[ $(stat -c '%a:%U:%G' bootstrap/cache/config.php) == 640:ubuntu:www-data ]] || fail 'Sensitive config cache has unexpected ownership.'
[[ -f .env && ! -L .env && $(sha256sum .env) == "$env_fingerprint" && $(stat -c '%a:%U:%G' .env) == 640:ubuntu:www-data ]] || fail '.env changed unexpectedly.'
phase=permissions
svc audit || fail 'Final www-data permission audit failed.'
phase=services
svc start-fpm || fail 'PHP-FPM start failed.'
# Workers and scheduler restart only after smoke checks so they cannot process new jobs prematurely.
run php8.4 artisan up || fail 'Could not leave maintenance mode.'
phase=smoke
smoke=failed
http_check() {
    local label=$1 expected=$2 url=$3 code
    if ! code=$(curl --disable --noproxy '*' --silent --output /dev/null --write-out '%{http_code}' --connect-timeout 5 --max-time 20 --header 'Accept: application/json' "$url"); then
        say "SMOKE $label transport=failed"
        return 1
    fi
    say "SMOKE $label expected=$expected actual=$code"
    [[ $code == "$expected" ]]
}
http_check internal-up 200 http://127.0.0.1:8080/up || fail 'Internal health check failed.'
http_check public-profile 401 https://student-ai-planner.duckdns.org/api/profile || fail 'Public profile check failed.'
http_check public-csrf 204 https://student-ai-planner.duckdns.org/sanctum/csrf-cookie || fail 'Public CSRF check failed.'
svc start-queue || fail 'Queue start failed.'
svc start-scheduler || fail 'Scheduler start failed.'
svc health || fail 'Service health check failed.'
say 'SMOKE php-fpm=active queue=active scheduler-timer=active'
checkout_status=$(git status --porcelain --untracked-files=all) || fail 'Final checkout inspection failed.'
[[ $(git rev-parse HEAD) == "$requested" && -z $checkout_status ]] || fail 'Final checkout verification failed.'
smoke=passed
# Marker removed last: cancellation/power loss always requires inspection rather than blind retry.
printf '%s\n' "$requested" > "$state/last-successful-sha"
rm -f "$state/in-progress"
say "DEPLOYMENT SUCCESS deployed=$requested checkout=$requested previous=$previous smoke=$smoke"
