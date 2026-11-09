"""Offline deployment contract tests. Real temporary Git; mocked PHP, SSH and services.

Production paths are replaced only in disposable copies of scripts. No production
configuration override or test bypass exists in the deployed scripts.
"""
import importlib.util
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]


class DeploymentTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.repo = self.base / 'backend'
        self.repo.mkdir()
        self.state = self.base / 'state'
        self.state.mkdir(mode=0o700)
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        self.log = self.base / 'commands'
        self.env = dict(os.environ, PATH=f'{self.bin}:{os.environ["PATH"]}',
                        FIXTURE=str(self.base), FAIL='', HTTP='200',
                        GIT_CONFIG_NOSYSTEM='1', GIT_CONFIG_GLOBAL='/dev/null')
        self.git_binary = shutil.which('git')
        self.git('init', '-q', '-b', 'main')
        self.git('config', 'user.email', 'fixture@example.invalid')
        self.git('config', 'user.name', 'Offline Fixture')
        self.git('remote', 'add', 'origin', 'https://github.com/BashukOleksii/Student-AI-Planner-Backend.git')
        for name in ('app', 'bootstrap/cache', 'config', 'database/migrations', 'public', 'resources', 'routes', 'vendor', 'storage/framework'):
            (self.repo / name).mkdir(parents=True, exist_ok=True)
        (self.repo / '.gitignore').write_text('.env\nvendor/\nstorage/\nbootstrap/cache/\n')
        for name in ('artisan', 'composer.json', 'composer.lock', 'app/Old.php'):
            (self.repo / name).write_text('fixture\n')
        (self.repo / '.env').write_text('SECRET=NEVER_PRINT_ME\n')
        (self.repo / '.env').chmod(0o640)
        self.old = self.commit('baseline')
        (self.repo / 'app/New.php').write_text('new tested code\n')
        self.sha = self.commit('tested target')
        self.git('update-ref', 'refs/remotes/origin/main', self.sha)
        self.git('checkout', '-q', self.old, '--', 'app')
        # Move fixture main back without any destructive production command.
        self.git('update-ref', 'refs/heads/main', self.old)
        self.git('read-tree', self.old)
        (self.repo / 'app/New.php').unlink()
        self.script = self.base / 'deploy.sh'
        source = (ROOT / 'scripts/deploy/deploy-backend.sh').read_text()
        source = source.replace('readonly root=/srv/student-ai-planner/backend', f'readonly root={self.repo}')
        source = source.replace('readonly state=/srv/student-ai-planner/backend-deploy', f'readonly state={self.state}')
        self.script.write_text(source)
        self.mock('id', '''if [[ $1 == -un ]]; then echo ubuntu; else echo 1000; fi''')
        self.mock('stat', '''case "${*: -1}" in
.env|bootstrap/cache/config.php) printf '%s:ubuntu:www-data\\n' "$(/usr/bin/stat -c %a "${*: -1}")" ;;
bootstrap/cache) echo 2770:www-data ;;
*) /usr/bin/stat "$@" ;;
esac''')
        self.mock('git', f'''if [[ $1 == fetch ]]; then
  [[ $FAIL != fetch ]] || exit 1
  exit 0
fi
if [[ $FAIL == sha-mismatch && $1 == rev-parse && ${{2:-}} == *'^{{commit}}' ]]; then
  printf '%040d\\n' 0; exit 0
fi
if [[ ( $FAIL == status-scan && $1 == status ) || ( $FAIL == migration-scan && $1 == diff ) || ( $FAIL == runtime-scan && $1 == ls-tree ) ]]; then exit 1; fi
exec {self.git_binary} "$@"''')
        self.mock('composer', '''echo "composer $* umask=$(umask)" >> "$FIXTURE/commands"
if [[ ( $FAIL == composer || $FAIL == containment-failure ) && $1 == install ]]; then echo 'SECRET=NEVER_PRINT_ME' >&2; exit 1; fi
if [[ $FAIL == interrupt && $1 == install ]]; then kill -TERM "$PPID"; exit 1; fi
if [[ $FAIL == unreadable && $1 == install ]]; then chmod 600 app/New.php; fi
if [[ $FAIL == env-change && $1 == install ]]; then echo unexpected >> .env; fi
exit 0''')
        self.mock('php', 'exit 0')
        self.mock('php8.4', '''echo "php $* umask=$(umask)" >> "$FIXTURE/commands"
[[ ${1:-} == artisan ]] || exit 0
if [[ $2 == config:clear && -f bootstrap/cache/packages.php ]]; then exit 1; fi
[[ $FAIL != "${2:-}" ]] || { echo 'SECRET=NEVER_PRINT_ME' >&2; exit 1; }
case "$2" in
 down) echo down > storage/framework/down; echo 'maintenance stub' > storage/framework/maintenance.php ;;
 up) rm -f storage/framework/down storage/framework/maintenance.php ;;
 config:cache) echo 'SECRET=CONFIG_MUST_STAY_PRIVATE' > bootstrap/cache/config.php ;;
esac''')
        self.mock('sudo', '''action=${*: -1}
echo "service $action" >> "$FIXTURE/commands"
[[ $FAIL != "$action" ]] || exit 1
if [[ $FAIL == containment-failure && $action == stop-fpm && -f app/New.php ]]; then exit 1; fi
if [[ $FAIL == final-health && $action == health && -f app/New.php ]]; then exit 1; fi
if [[ $action == audit ]]; then
  if [[ -f app/New.php ]]; then
    [[ $(/usr/bin/stat -c %a app/New.php) == 644 ]] || exit 1
    [[ $(/usr/bin/stat -c %a bootstrap/cache/config.php) == 640 ]] || exit 1
  fi
fi''')
        self.mock('curl', '''echo "curl $*" >> "$FIXTURE/commands"
if [[ $FAIL == transport ]]; then exit 28; fi
if [[ $FAIL == smoke || ( $FAIL == profile-smoke && ${*: -1} == */api/profile ) || ( $FAIL == csrf-smoke && ${*: -1} == */sanctum/csrf-cookie ) ]]; then echo 500; exit 0; fi
case "${*: -1}" in
 */up) echo 200 ;;
 */api/profile) echo 401 ;;
 */sanctum/csrf-cookie) echo 204 ;;
 *) exit 1 ;;
esac''')

    def mock(self, name, body):
        path = self.bin / name
        path.write_text('#!/bin/bash\nset -euo pipefail\n' + body + '\n')
        path.chmod(0o755)

    def git(self, *args):
        return subprocess.check_output([self.git_binary, *args], cwd=self.repo,
                                       env=self.env, stderr=subprocess.DEVNULL, text=True).strip()

    def commit(self, message):
        self.git('add', '.')
        self.git('commit', '-qm', message)
        return self.git('rev-parse', 'HEAD')

    def deploy(self, sha=None, fail=''):
        env = dict(self.env, FAIL=fail)
        result = subprocess.run(['bash', '-c', 'umask 077; exec bash "$1" "$2"',
                                 'fixture', str(self.script), sha or self.sha],
                                env=env, capture_output=True, text=True, timeout=15)
        self.output = result.stdout + result.stderr
        self.assertNotIn('NEVER_PRINT_ME', self.output)
        self.assertNotIn('CONFIG_MUST_STAY_PRIVATE', self.output)
        return result

    def test_exact_tested_sha_with_inherited_restrictive_umask(self):
        self.assertEqual(0, self.deploy().returncode, self.output)
        self.assertEqual(self.sha, self.git('rev-parse', 'HEAD'))
        self.assertIn(f'DEPLOYMENT SUCCESS deployed={self.sha}', self.output)
        self.assertIn('smoke=passed', self.output)
        self.assertEqual(self.sha, (self.state / 'last-successful-sha').read_text().strip())
        self.assertFalse((self.state / 'in-progress').exists())
        self.assertEqual(0o644, (self.repo / 'app/New.php').stat().st_mode & 0o777)
        self.assertEqual(0o640, (self.repo / 'bootstrap/cache/config.php').stat().st_mode & 0o777)
        self.assertEqual('SECRET=NEVER_PRINT_ME\n', (self.repo / '.env').read_text())
        commands = self.log.read_text()
        self.assertIn('composer install --no-dev', commands)
        self.assertIn('--no-scripts --no-plugins umask=0022', commands)
        self.assertIn('php artisan config:cache umask=0007', commands)
        self.assertLess(commands.index('service stop-fpm'), commands.index('composer install'))
        self.assertLess(commands.index('/sanctum/csrf-cookie'), commands.index('service start-queue'))
        for forbidden in ('migrate', 'key:generate', 'composer update', 'composer setup'):
            self.assertNotIn(forbidden, commands)

    def test_stale_provider_manifests_are_removed_before_artisan_bootstrap(self):
        for file in ('config.php', 'services.php', 'packages.php', 'routes-v7.php', 'events.php'):
            (self.repo / 'bootstrap/cache' / file).write_text('stale provider classes')
        self.assertEqual(0, self.deploy().returncode, self.output)
        self.assertFalse((self.repo / 'bootstrap/cache/packages.php').exists())
        self.assertIn('smoke=passed', self.output)

    def test_failed_git_safety_scans_refuse_deployment(self):
        for failure in ('fetch', 'status-scan', 'migration-scan', 'runtime-scan'):
            with self.subTest(failure=failure):
                self.assertNotEqual(0, self.deploy(fail=failure).returncode)
                self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))
                self.assertFalse((self.state / 'in-progress').exists())
                self.assertIn('DEPLOYMENT FAILURE', self.output)

    def test_dirty_checkout_refused(self):
        (self.repo / 'app/Old.php').write_text('dirty')
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('Dirty checkout', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))

    def test_untracked_file_refused(self):
        (self.repo / 'app/Unexpected.php').write_text('untracked')
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('Dirty checkout', self.output)

    def test_diverged_branch_refused(self):
        (self.repo / 'app/Local.php').write_text('local change')
        self.commit('local divergence')
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('diverged', self.output)

    def test_wrong_branch_and_repository_refused(self):
        self.git('symbolic-ref', 'HEAD', 'refs/heads/other')
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('branch must be main', self.output)
        self.git('symbolic-ref', 'HEAD', 'refs/heads/main')
        self.git('remote', 'set-url', 'origin', 'https://example.invalid/other')
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('origin repository', self.output)

    def test_sha_mismatch_refused(self):
        self.assertNotEqual(0, self.deploy(fail='sha-mismatch').returncode)
        self.assertIn('SHA mismatch', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))

    def test_unavailable_or_off_branch_sha_refused(self):
        self.assertNotEqual(0, self.deploy('a' * 40).returncode)
        self.assertIn('SHA mismatch', self.output)
        (self.repo / 'app/Other.php').write_text('off branch')
        off_branch = self.commit('not in origin main')
        self.assertNotEqual(0, self.deploy(off_branch).returncode)
        self.assertIn('not on origin/main', self.output)

    def test_migration_changes_require_review(self):
        (self.repo / 'database/migrations/new.php').write_text('schema change')
        migration_sha = self.commit('migration')
        self.git('update-ref', 'refs/remotes/origin/main', migration_sha)
        # The server stays at its old code, target contains a new migration.
        self.git('update-ref', 'refs/heads/main', self.old)
        self.git('read-tree', self.old)
        (self.repo / 'database/migrations/new.php').unlink()
        self.assertNotEqual(0, self.deploy(migration_sha).returncode)
        self.assertIn('Migration changes', self.output)
        self.assertFalse((self.state / 'in-progress').exists())

    def test_existing_migration_modification_and_deletion_require_review(self):
        for action in ('modify', 'delete'):
            with self.subTest(action=action):
                migration = self.repo / 'database/migrations/existing.php'
                migration.write_text('original')
                baseline = self.commit('existing migration baseline')
                if action == 'modify':
                    migration.write_text('modified migration')
                else:
                    migration.unlink()
                target = self.commit('changed migration')
                self.git('update-ref', 'refs/remotes/origin/main', target)
                self.git('checkout', '-q', baseline, '--', 'database/migrations')
                self.git('update-ref', 'refs/heads/main', baseline)
                self.git('read-tree', baseline)
                self.assertNotEqual(0, self.deploy(target).returncode)
                self.assertIn('Migration changes', self.output)
                self.assertEqual(baseline, self.git('rev-parse', 'HEAD'))
                self.setUpFreshFixture()

    def test_ignored_server_owned_files_cannot_be_overwritten(self):
        (self.repo / '.gitignore').write_text((self.repo / '.gitignore').read_text() + 'server-file\n')
        baseline = self.commit('ignored runtime file')
        (self.repo / 'server-file').write_text('tracked incoming version')
        self.git('add', '-f', 'server-file')
        self.git('commit', '-qm', 'incoming ignored path conflict')
        target = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/remotes/origin/main', target)
        self.git('update-ref', 'refs/heads/main', baseline)
        self.git('read-tree', baseline)
        (self.repo / 'server-file').write_text('server-owned original')
        self.assertNotEqual(0, self.deploy(target).returncode)
        self.assertIn('Fast-forward failed', self.output)
        self.assertEqual('server-owned original', (self.repo / 'server-file').read_text())

    def test_composer_cache_permissions_and_service_failures_fail_closed(self):
        for failure in ('composer', 'config:clear', 'clear-compiled', 'package:discover',
                        'config:cache', 'route:cache', 'view:cache', 'start-fpm', 'start-queue', 'start-scheduler',
                        'health', 'final-health', 'unreadable', 'interrupt', 'profile-smoke', 'csrf-smoke', 'transport', 'env-change'):
            with self.subTest(failure=failure):
                # health fails preflight; all others happen after mutation.
                result = self.deploy(fail=failure)
                self.assertNotEqual(0, result.returncode)
                self.assertIn('DEPLOYMENT FAILURE', self.output)
                self.assertNotIn('DEPLOYMENT SUCCESS', self.output)
                if failure != 'health':
                    self.assertTrue((self.repo / 'storage/framework/down').exists())
                    self.assertTrue((self.repo / 'storage/framework/maintenance.php').exists())
                    self.assertTrue((self.state / 'in-progress').exists())
                    self.assertIn(f'checkout={self.sha}', self.output)
                    self.assertIn('service stop-fpm', self.log.read_text())
                self.setUpFreshFixture()

    def setUpFreshFixture(self):
        # Each subcase needs independent Git/runtime state; cleanup remains registered.
        self.setUp()

    def test_failed_smoke_check_stops_services_and_keeps_recovery_marker(self):
        self.assertNotEqual(0, self.deploy(fail='smoke').returncode)
        self.assertIn('SMOKE internal-up expected=200 actual=500', self.output)
        self.assertIn('smoke=failed healthy=unverified', self.output)
        self.assertTrue((self.repo / 'storage/framework/down').exists())
        self.assertTrue((self.state / 'in-progress').exists())
        self.assertFalse((self.state / 'last-successful-sha').exists())

    def test_containment_failure_reports_unverified_service_state(self):
        self.assertNotEqual(0, self.deploy(fail='containment-failure').returncode)
        self.assertIn('CRITICAL: containment', self.output)
        self.assertIn('fpm-stop=failed', self.output)
        self.assertIn('healthy=unverified', self.output)
        self.assertNotIn('Backend stopped', self.output)
        self.assertTrue((self.state / 'in-progress').exists())

    def test_recoverable_pre_mutation_failure_reopens_previous_installation(self):
        self.assertNotEqual(0, self.deploy(fail='stop-queue').returncode)
        self.assertIn('Previous installation restored before code mutation', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))
        self.assertFalse((self.repo / 'storage/framework/down').exists())
        self.assertFalse((self.state / 'in-progress').exists())

    def test_env_permission_regression_refused_before_mutation(self):
        (self.repo / '.env').chmod(0o644)
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('Expected .env mode/owner', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))
        self.assertFalse((self.state / 'in-progress').exists())

    def test_initial_permission_failure_does_not_mutate_checkout(self):
        self.assertNotEqual(0, self.deploy(fail='audit').returncode)
        self.assertIn('permission audit failed', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))

    def test_interrupted_deployment_is_not_blindly_retried(self):
        (self.state / 'in-progress').write_text('interrupted')
        self.assertNotEqual(0, self.deploy().returncode)
        self.assertIn('operator recovery', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))

    def test_concurrent_deployment_refused(self):
        import fcntl
        with (self.state / 'lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertNotEqual(0, self.deploy().returncode)
            self.assertIn('holds the lock', self.output)
        self.assertEqual(self.old, self.git('rev-parse', 'HEAD'))


class RunnerGuardTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        self.env = dict(os.environ, PATH=f'{self.bin}:{os.environ["PATH"]}',
                        DEPLOY_ENABLED='true', VERIFY_RESULT='success',
                        GITHUB_EVENT_NAME='push', GITHUB_REF='refs/heads/main',
                        GITHUB_SHA='a' * 40,
                        GITHUB_REPOSITORY='BashukOleksii/Student-AI-Planner-Backend',
                        LIGHTSAIL_BACKEND_SSH_PRIVATE_KEY='PRIVATE_TEST_VALUE',
                        LIGHTSAIL_BACKEND_KNOWN_HOSTS='PINNED_TEST_VALUE')
        self.log = self.base / 'ssh-args'
        # All SSH commands are mocked, even tests that expect early refusal.
        for name, content in {
            'ssh': f'printf "%s\\n" "$@" > "{self.log}"; exit "${{SSH_RESULT:-0}}"',
            'ssh-keygen': 'exit "${KEY_RESULT:-0}"',
        }.items():
            path = self.bin / name
            path.write_text('#!/bin/bash\n' + content + '\n')
            path.chmod(0o755)

    def invoke(self, **overrides):
        return subprocess.run(['bash', str(ROOT / '.github/scripts/deploy-lightsail.sh')],
                              env=dict(self.env, **overrides), capture_output=True, text=True, timeout=5)

    def test_failed_ci_pr_disabled_flag_and_wrong_context_never_use_ssh(self):
        for overrides in ({'VERIFY_RESULT': 'failure'}, {'VERIFY_RESULT': 'cancelled'},
                          {'VERIFY_RESULT': 'skipped'}, {'GITHUB_EVENT_NAME': 'pull_request'},
                          {'GITHUB_EVENT_NAME': 'workflow_dispatch'}, {'GITHUB_REF': 'refs/heads/other'},
                          {'GITHUB_REPOSITORY': 'other/repo'}, {'DEPLOY_ENABLED': 'false'},
                          {'DEPLOY_ENABLED': ''}, {'GITHUB_SHA': 'main'}):
            with self.subTest(overrides=overrides):
                result = self.invoke(**overrides)
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(self.log.exists())

    def test_missing_credentials_or_untrusted_host_never_connects(self):
        for overrides in ({'LIGHTSAIL_BACKEND_SSH_PRIVATE_KEY': ''},
                          {'LIGHTSAIL_BACKEND_KNOWN_HOSTS': ''}, {'KEY_RESULT': '1'}):
            result = self.invoke(**overrides)
            self.assertNotEqual(0, result.returncode)
            self.assertFalse(self.log.exists())
            self.assertNotIn('PRIVATE_TEST_VALUE', result.stdout + result.stderr)
        # Valid private key, but ssh-keygen cannot find the pinned host.
        (self.bin / 'ssh-keygen').write_text('#!/bin/bash\n[[ $1 != -F ]]\n')
        self.assertNotEqual(0, self.invoke().returncode)
        self.assertFalse(self.log.exists())

    def test_strict_pinning_exact_sha_no_forwarding_and_ssh_failure(self):
        self.assertEqual(0, self.invoke().returncode)
        args = self.log.read_text()
        for option in ('StrictHostKeyChecking=yes', 'IdentityAgent=none', 'ForwardAgent=no',
                       'GlobalKnownHostsFile=/dev/null', 'BatchMode=yes'):
            self.assertIn(option, args)
        self.assertIn('/usr/local/libexec/student-planner-backend-deploy ' + 'a' * 40, args)
        self.assertNotIn('PRIVATE_TEST_VALUE', args)
        self.assertNotEqual(0, self.invoke(SSH_RESULT='255').returncode)

    def test_workflow_requires_verification_and_preserves_existing_checks(self):
        workflow = (ROOT / '.github/workflows/backend-verification.yml').read_text()
        deploy = workflow.split('\n  deploy:\n')[1]
        for clause in ('needs: verify', "needs.verify.result == 'success'", "github.event_name == 'push'",
                       "github.ref == 'refs/heads/main'", "vars.LIGHTSAIL_BACKEND_DEPLOY_ENABLED == 'true'",
                       'name: production', 'cancel-in-progress: false', 'ref: ${{ github.sha }}'):
            self.assertIn(clause, deploy)
        for check in ('mysql:8.4', 'verify-ci-migrations.php', 'vendor/bin/pint --test',
                      'vendor/bin/phpunit --log-junit phpunit-full.xml', 'phpunit-gemini-actions.xml',
                      'python3 -m unittest discover -s tests/Deployment -v'):
            self.assertIn(check, workflow)


class PermissionAuditTest(unittest.TestCase):
    def test_sensitive_cache_and_env_reject_world_readable_files(self):
        spec = importlib.util.spec_from_file_location('permissions', ROOT / 'scripts/deploy/check-permissions.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            for name in ('app', 'bootstrap/cache', 'config', 'database', 'public', 'resources', 'routes', 'vendor', 'storage/framework'):
                (root / name).mkdir(parents=True, exist_ok=True)
            for name in ('artisan', 'composer.json', 'composer.lock', '.env', 'bootstrap/cache/config.php', 'bootstrap/app.php', 'public/index.php', 'vendor/autoload.php'):
                (root / name).write_text('fixture')
            for path in (root / '.env', root / 'bootstrap/cache/config.php'):
                path.chmod(0o640)
            for name in ('storage', 'storage/framework', 'bootstrap/cache'):
                (root / name).chmod(0o2770)
            module.audit(root)
            for name in ('.env', 'bootstrap/cache/config.php'):
                with self.subTest(name=name):
                    path = root / name
                    path.chmod(0o644)
                    with self.assertRaises(RuntimeError):
                        module.audit(root)
                    path.chmod(0o640)
            code = root / 'app/Unreadable.php'
            code.write_text('code')
            code.chmod(0o600)
            actual_access = os.access
            # Model the non-owner PHP-FPM identity, even if CI owns the temporary file.
            with patch.object(module.os, 'access', side_effect=lambda path, mode: False if Path(path) == code else actual_access(path, mode)):
                with self.assertRaises(RuntimeError):
                    module.audit(root)


class InstalledHelperTest(unittest.TestCase):
    def test_privileged_helper_accepts_only_fixed_operations_and_propagates_failure(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            log = base / 'calls'
            mock = base / 'systemctl'
            mock.write_text(f'#!/bin/bash\nprintf "%s\\n" "$*" >> "{log}"\nexit "${{FIXTURE_EXIT:-0}}"\n')
            mock.chmod(0o755)
            runuser = base / 'runuser'
            runuser.write_text(f'#!/bin/bash\nprintf "%s\\n" "$*" >> "{log}"\n')
            runuser.chmod(0o755)
            source = (ROOT / 'scripts/deploy/student-planner-backend-control').read_text()
            source = source.replace('/usr/bin/systemctl', str(mock)).replace('/usr/sbin/runuser', str(runuser))
            helper = base / 'control'
            helper.write_text(source)
            for operation in ('stop-fpm', 'start-fpm', 'stop-queue', 'start-queue',
                              'stop-scheduler', 'start-scheduler', 'health', 'audit'):
                self.assertEqual(0, subprocess.run(['bash', str(helper), operation]).returncode)
            self.assertIn('stop student-ai-planner-scheduler.service', log.read_text())
            self.assertIn('-u www-data -- /usr/bin/python3 -I /usr/local/libexec/student-planner-backend-permissions.py', log.read_text())
            original = log.read_text()
            for args in ([], ['restart-anything'], ['start-fpm', 'arbitrary.service'], ['audit; id']):
                self.assertNotEqual(0, subprocess.run(['bash', str(helper), *args]).returncode)
            self.assertEqual(original, log.read_text())
            self.assertNotEqual(0, subprocess.run(['bash', str(helper), 'health'],
                                                  env=dict(os.environ, FIXTURE_EXIT='1')).returncode)

    def test_forced_ssh_command_cannot_inject_commands_or_run_arbitrary_sha(self):
        with tempfile.TemporaryDirectory() as temp:
            base = Path(temp)
            log = base / 'args'
            target = base / 'deploy'
            target.write_text(f'#!/bin/bash\nprintf "%s" "$1" > "{log}"\n')
            target.chmod(0o755)
            source = (ROOT / 'scripts/deploy/ssh-dispatch').read_text()
            # Preserve the production command regex; replace only the exec target.
            source = source.replace('exec /usr/local/libexec/student-planner-backend-deploy', 'exec ' + str(target))
            dispatch = base / 'dispatch'
            dispatch.write_text(source)
            prefix = '/usr/local/libexec/student-planner-backend-deploy '
            for command in ('', 'bash', prefix + 'main', prefix + 'a' * 40 + '; id', prefix + 'a' * 41):
                result = subprocess.run(['bash', str(dispatch)], capture_output=True,
                                        env=dict(os.environ, SSH_ORIGINAL_COMMAND=command))
                self.assertNotEqual(0, result.returncode)
                self.assertFalse(log.exists())
            result = subprocess.run(['bash', str(dispatch)],
                                    env=dict(os.environ, SSH_ORIGINAL_COMMAND=prefix + 'a' * 40))
            self.assertEqual(0, result.returncode)
            self.assertEqual('a' * 40, log.read_text())


class SyntaxTest(unittest.TestCase):
    def test_embedded_php_preflight_checks_parse_without_bootstrapping_laravel(self):
        import shlex
        lines = (ROOT / 'scripts/deploy/deploy-backend.sh').read_text().splitlines()
        checks = [shlex.split(line) for line in lines if line.startswith('run php') and ' -r ' in line]
        self.assertEqual(3, len(checks))
        for tokens in checks:
            code = tokens[tokens.index('-r') + 1]
            result = subprocess.run(['php', '-l'], input='<?php\n' + code,
                                    text=True, capture_output=True, timeout=5)
            self.assertEqual(0, result.returncode, result.stdout + result.stderr)
