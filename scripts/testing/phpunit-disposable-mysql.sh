#!/usr/bin/env bash
# Local, temporary MySQL only. Never discovers, migrates or drops an existing server's database.
set -euo pipefail
cd "$(dirname "$0")/../.."
for tool in mysqld mysql mysqladmin php openssl; do
    command -v "$tool" >/dev/null || { echo "Required test tool missing: $tool" >&2; exit 2; }
done
run_dir=$(mktemp -d "${TMPDIR:-/tmp}/planner-phpunit.XXXXXXXX")
mysql_pid=''
cleanup() {
    if [[ -n "$mysql_pid" ]] && kill -0 "$mysql_pid" 2>/dev/null; then
        MYSQL_PWD='' mysqladmin --no-defaults --socket="$run_dir/mysql.sock" -u root shutdown >/dev/null 2>&1 || kill "$mysql_pid" 2>/dev/null || true
        wait "$mysql_pid" 2>/dev/null || true
    fi
    rm -rf -- "$run_dir"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir "$run_dir/data"
mysqld --no-defaults --initialize-insecure --datadir="$run_dir/data" --log-error="$run_dir/initialize.log"
mysqld --no-defaults --datadir="$run_dir/data" --socket="$run_dir/mysql.sock" \
    --port="${PLANNER_TEST_PORT:-13380}" --bind-address=127.0.0.1 --mysqlx=0 --skip-log-bin \
    --pid-file="$run_dir/mysql.pid" --log-error="$run_dir/mysql.log" --innodb-buffer-pool-size=128M &
mysql_pid=$!
for attempt in {1..300}; do
    if [[ -S "$run_dir/mysql.sock" ]] && MYSQL_PWD='' mysqladmin --no-defaults --socket="$run_dir/mysql.sock" -u root ping >/dev/null 2>&1; then
        break
    fi
    kill -0 "$mysql_pid" 2>/dev/null || { cat "$run_dir/mysql.log" >&2; exit 2; }
    sleep 0.1
done
mysql_cli=(mysql --no-defaults --socket="$run_dir/mysql.sock" -u root --batch --skip-column-names)
export MYSQL_PWD=''
actual_data_dir=$("${mysql_cli[@]}" -e 'SELECT @@datadir')
[[ "$actual_data_dir" == "$run_dir/data/" ]] || { echo 'Disposable server identity mismatch.' >&2; exit 2; }
existing=$("${mysql_cli[@]}" -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name NOT IN ('mysql','sys','performance_schema','information_schema')")
[[ "$existing" == 0 ]] || { echo 'Fresh server unexpectedly contains application schemas.' >&2; exit 2; }
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="${PLANNER_TEST_PORT:-13380}"
export DB_SOCKET="$run_dir/mysql.sock" DB_USERNAME=root DB_PASSWORD='' DB_URL=''
export PLANNER_TEST_DATABASE="student_planner_isolated_$(openssl rand -hex 8)"
export DB_DATABASE="$PLANNER_TEST_DATABASE"
export APP_KEY="base64:$(openssl rand -base64 32)" APP_CONFIG_CACHE="$run_dir/no-cached-config.php"
export MAIL_MAILER=array QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array
export OPENAI_API_KEY=offline-test-only GEMINI_API_KEY=offline-test-only AI_LIVE_SMOKE=0 AI_LIVE_ALLOW_PAID=0
"${mysql_cli[@]}" -e "CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
empty=$("${mysql_cli[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE'")
[[ "$empty" == 0 ]] || { echo 'Disposable test database was not empty.' >&2; exit 2; }
printf 'Verified freshly initialized MySQL: database=%s, datadir=%s, port=%s\n' "$DB_DATABASE" "$actual_data_dir" "$DB_PORT" >&2
php vendor/bin/phpunit "$@"
