#!/usr/bin/env bash
# Local, temporary MySQL only. Never discovers, migrates or drops an existing server's database.
set -euo pipefail
umask 077
frontend=$(realpath "${1:?Pass the independent frontend repository path}")
shift
[[ -f "$frontend/playwright.fullstack.config.ts" ]] || { echo 'Frontend full-stack configuration not found.' >&2; exit 2; }
cd "$(dirname "$0")/../.."
for tool in mysqld mysql mysqladmin php openssl curl node npm npx realpath; do
    command -v "$tool" >/dev/null || { echo "Required test tool missing: $tool" >&2; exit 2; }
done
node -e 'const net=require("node:net"); Promise.all([18000,15173].map(port=>new Promise((resolve,reject)=>{const server=net.createServer(); server.once("error",reject); server.listen(port,"localhost",()=>server.close(resolve));}))).catch(()=>{process.stderr.write("System-test HTTP ports must be unused.\n");process.exitCode=2;});'
run_dir=$(mktemp -d "${TMPDIR:-/tmp}/planner-system.XXXXXXXX")
mysql_pid=''
http_pid=''
vite_pid=''
mkdir -p "$frontend/fullstack-results"
evidence="$frontend/fullstack-results"
cp /dev/null "$evidence/server.log"
printf '[]\n' > "$evidence/checks.json"
cleanup() {
    for child in "$http_pid" "$vite_pid"; do
        if [[ -n "$child" ]]; then kill "$child" 2>/dev/null || true; wait "$child" 2>/dev/null || true; fi
    done
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
    --port="${PLANNER_TEST_PORT:-13381}" --bind-address=127.0.0.1 --mysqlx=0 --skip-log-bin \
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
export APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="${PLANNER_TEST_PORT:-13381}"
export DB_SOCKET="$run_dir/mysql.sock" DB_USERNAME=root DB_PASSWORD='' DB_URL=''
export PLANNER_TEST_DATABASE="student_planner_isolated_$(openssl rand -hex 8)"
export DB_DATABASE="$PLANNER_TEST_DATABASE"
export APP_KEY="base64:$(openssl rand -base64 32)" APP_CONFIG_CACHE="$run_dir/no-cached-config.php"
export MAIL_MAILER=log MAIL_LOG_CHANNEL=single QUEUE_CONNECTION=database CACHE_STORE=database SESSION_DRIVER=database
export APP_ENV=local APP_DEBUG=false APP_URL=http://localhost:18000 FRONTEND_URL=http://localhost:15173
export CORS_ALLOWED_ORIGINS=http://localhost:15173 SANCTUM_STATEFUL_DOMAINS=localhost:15173
export SESSION_COOKIE=planner_system_session SESSION_DOMAIN=null SESSION_SECURE_COOKIE=false SESSION_SAME_SITE=lax
export LOG_CHANNEL=single LOG_LEVEL=debug LARAVEL_STORAGE_PATH="$run_dir/storage"
mkdir -p "$LARAVEL_STORAGE_PATH/framework/cache/data" "$LARAVEL_STORAGE_PATH/framework/sessions" "$LARAVEL_STORAGE_PATH/framework/views" "$LARAVEL_STORAGE_PATH/logs"
export PLANNER_SYSTEM_TEST=1 PLANNER_SYSTEM_RUN="$run_dir" PLANNER_BACKEND_ROOT="$PWD"
export VITE_API_BASE_URL=http://localhost:18000 AI_PROVIDER=openai OPENAI_MODEL=system-test-only
export AI_REQUESTS_PER_MINUTE=60
export OPENAI_API_KEY=offline-test-only GEMINI_API_KEY=offline-test-only AI_LIVE_SMOKE=0 AI_LIVE_ALLOW_PAID=0
"${mysql_cli[@]}" -e "CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
empty=$("${mysql_cli[@]}" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_DATABASE'")
[[ "$empty" == 0 ]] || { echo 'Disposable test database was not empty.' >&2; exit 2; }
printf 'Verified freshly initialized MySQL: database=%s, datadir=%s, port=%s\n' "$DB_DATABASE" "$actual_data_dir" "$DB_PORT" >&2
# Proof is emitted only after initialization, identity and empty-schema checks above.
php -r '$p=new PDO("mysql:unix_socket=".getenv("DB_SOCKET").";dbname=".getenv("DB_DATABASE"),"root",""); $r=$p->query("SELECT @@datadir AS datadir, @@server_uuid AS uuid, DATABASE() AS `database`, VERSION() AS version, CURRENT_USER() AS account")->fetch(PDO::FETCH_ASSOC); $r["initial_tables"]=0; $r["initial_application_schemas"]=0; file_put_contents(getenv("PLANNER_SYSTEM_RUN")."/isolation.json",json_encode($r,JSON_PRETTY_PRINT));'
cp "$run_dir/isolation.json" "$evidence/isolation.json"
php artisan migrate --no-interaction > "$evidence/migrate.log" 2>&1
php artisan schedule:list > "$evidence/schedule-list.log" 2>&1
php -S localhost:18000 tests/Support/system-router.php > "$evidence/server.log" 2>&1 &
http_pid=$!
(cd "$frontend"; exec npm run dev -- --mode integration --host localhost --port 15173 --strictPort) > "$evidence/vite.log" 2>&1 &
vite_pid=$!
for attempt in {1..100}; do
    kill -0 "$http_pid" && kill -0 "$vite_pid" || exit 2
    if curl --silent --fail http://localhost:18000/sanctum/csrf-cookie >/dev/null && curl --silent --fail http://localhost:15173 >/dev/null; then break; fi
    kill -0 "$http_pid" && kill -0 "$vite_pid" || exit 2
    sleep 0.2
done
export PLANNER_SYSTEM_HTTP_PID="$http_pid" PLANNER_SYSTEM_VITE_PID="$vite_pid" PLANNER_SYSTEM_MYSQL_PID="$mysql_pid"
php -r 'file_put_contents($argv[1],json_encode(["backend_pid"=>(int)getenv("PLANNER_SYSTEM_HTTP_PID"),"vite_pid"=>(int)getenv("PLANNER_SYSTEM_VITE_PID"),"mysql_pid"=>(int)getenv("PLANNER_SYSTEM_MYSQL_PID"),"backend_url"=>getenv("APP_URL"),"frontend_url"=>getenv("FRONTEND_URL"),"mail"=>getenv("MAIL_MAILER"),"queue"=>getenv("QUEUE_CONNECTION"),"ai"=>"Tests\\Support\\SystemTestProvider (no network)"],JSON_PRETTY_PRINT));' "$evidence/processes.json"
cd "$frontend"
npx playwright test --config playwright.fullstack.config.ts "$@"
