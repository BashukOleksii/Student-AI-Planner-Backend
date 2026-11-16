<?php

namespace App\Console\Commands;

use App\Exceptions\ScheduleWorkbookParserException;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Rules\BcryptPassword;
use App\Services\Imports\SemesterScheduleImportService;
use App\Services\Showcase\ShowcaseDatabase;
use App\Services\Showcase\ShowcasePreparationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class PrepareShowcase extends Command
{
    protected $signature = 'showcase:prepare
        {file : Path to the semester XLSX (Sheet1, ПІ-231)}
        {--starts-on= : Academic period start, YYYY-MM-DD}
        {--ends-on= : Academic period end, YYYY-MM-DD}
        {--show-on= : Presentation date in Europe/Kyiv (default: today)}
        {--dry-run : Validate and display the target without writing anything}';

    protected $description = 'Prepare Oleksii’s showcase account using a semester XLSX and an atomic replacement';

    public function handle(ShowcaseDatabase $database, SemesterScheduleImportService $imports, ShowcasePreparationService $preparation): int
    {
        $snapshot = null;
        $password = $confirmation = null;
        try {
            if (! $this->option('dry-run') && $this->output->getVerbosity() < OutputInterface::VERBOSITY_NORMAL) {
                throw new RuntimeException('Запис потребує видимого огляду цільової БД: приберіть --quiet/--silent.');
            }
            if (! $this->input->isInteractive()) {
                if (! $this->option('dry-run') || ! $this->option('starts-on') || ! $this->option('ends-on')) {
                    throw new RuntimeException('Запис потребує інтерактивного SSH-термінала; для неінтерактивного --dry-run передайте обидві дати.');
                }
            }
            $starts = $this->option('starts-on') ?? $this->ask('Початок періоду (YYYY-MM-DD)');
            $ends = $this->option('ends-on') ?? $this->ask('Кінець періоду (YYYY-MM-DD)');
            $show = $this->option('show-on') ?? CarbonImmutable::now(ShowcasePreparationService::TIMEZONE)->toDateString();
            Validator::make(['starts' => $starts, 'ends' => $ends, 'show' => $show], [
                'starts' => ['required', 'date_format:Y-m-d', 'after_or_equal:1000-01-01', 'before_or_equal:show'],
                'ends' => ['required', 'date_format:Y-m-d', 'after_or_equal:show', 'before_or_equal:9998-12-31'],
                'show' => ['required', 'date_format:Y-m-d'],
            ])->validate();
            $today = CarbonImmutable::now(ShowcasePreparationService::TIMEZONE)->toDateString();
            if ($starts > $today || $show < $today || $show > CarbonImmutable::parse($today)->addDays(30)->toDateString()) {
                throw new RuntimeException('Період має охоплювати сьогодні; дата показу — сьогодні або протягом наступних 30 днів.');
            }
            $identity = $database->inspect();
            $this->table(['Параметр', 'Фактичне значення'], [
                ['БД', $identity['database']], ['Сервер', $identity['hostname'].':'.$identity['port']],
                ['Endpoint', $identity['endpoint']], ['Server UUID', $identity['server_uuid']],
                ['Середовище', app()->environment()], ['Період: 1 семестр', "$starts — $ends"],
                ['Дата показу (Europe/Kyiv)', $show],
            ]);
            $existing = User::where('email', ShowcasePreparationService::EMAIL)->first();
            $this->warn($existing === null
                ? 'Наявного акаунта немає. Буде створено '.ShowcasePreparationService::EMAIL
                : 'БУДЕ ВИДАЛЕНО акаунт '.ShowcasePreparationService::EMAIL.' (id='.$existing->id.') і всі його дані, сесії та токени; буде створено новий акаунт.');
            $path = realpath($this->argument('file'));
            if ($path === false || ! is_file($path) || ! is_readable($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'xlsx') {
                throw new RuntimeException('Вкажіть доступний для читання файл .xlsx.');
            }
            // All subsequent reads use a private, stable snapshot of the reviewed bytes.
            $snapshot = tempnam(sys_get_temp_dir(), 'showcase-');
            if ($snapshot === false || ! chmod($snapshot, 0600) || ! copy($path, $snapshot)) {
                throw new RuntimeException('Не вдалося створити приватну тимчасову копію XLSX.');
            }
            $hash = hash_file('sha256', $snapshot);
            $inspection = $imports->inspectNewPeriod($snapshot, $starts, $ends, ShowcasePreparationService::TIMEZONE);
            $template = $inspection['template'];
            $errors = $inspection['review']['errors'];
            foreach ($inspection['review']['rows'] as $row) {
                if ($row['errors'] !== []) {
                    $errors['row_'.$row['row_number']] = $row['errors'];
                }
            }
            if ($errors !== []) {
                throw new RuntimeException('XLSX відхилено: '.json_encode($errors, JSON_UNESCAPED_UNICODE));
            }
            if ($template['group'] !== 'ПІ-231' || $template['source']['worksheet'] !== 'Sheet1') {
                throw new RuntimeException('Очікується аркуш Sheet1 і група ПІ-231.');
            }
            $split = collect($template['rules'])->where('source.pair_range', 'B30:F33');
            if ($split->count() !== 2 || $split->pluck('parity')->sort()->values()->all() !== ['denominator', 'numerator']
                || $split->contains(fn ($rule) => $rule['weekday'] !== 2)) {
                throw new RuntimeException('Блок Sheet1!B30:F33 має містити два заняття вівторка: чисельник і знаменник.');
            }
            $this->info('XLSX перевірено штатним семестровим механізмом: Sheet1, ПІ-231, '.count($template['rules']).' правил занять.');
            foreach ($split as $rule) {
                $this->line('B30:F33: '.$rule['parity'].' — '.$rule['subject_name'].'; '.$rule['starts_at_local'].'–'.$rule['ends_at_local'].'; '.$rule['teacher_name'].'; ауд. '.($rule['room'] ?? '—'));
            }
            $this->line('Перший тиждень періоду — чисельник; прив’язка до понеділка тижня початку періоду.');
            $this->line('Самостійне навчання: сесії 30–60 хв, перерва 15 хв, ліміти 120 хв/день і 480 хв/тиждень.');
            if ($this->option('dry-run')) {
                $this->info('DRY RUN: записів немає. Остаточна місткість роботи перевірятиметься всередині транзакції звичайного запуску.');

                return self::SUCCESS;
            }
            if ($this->ask('Для підтвердження введіть повну email-адресу') !== ShowcasePreparationService::EMAIL) {
                throw new RuntimeException('Підтвердження неправильне. Дані не змінено.');
            }
            $password = $this->secret('Новий пароль', fallback: false);
            $confirmation = $this->secret('Повторіть новий пароль', fallback: false);
            $rules = (new RegisterRequest)->rules()['password'];
            $rules[] = new BcryptPassword;
            Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => $rules])->validate();
            $result = $preparation->prepare($snapshot, $hash, $starts, $ends, $show, $existing?->id, $password, basename($path));
            $this->info('Готово: '.$result['email'].'; група '.$result['group'].'; період '.$result['period']);
            $this->line('Правил занять: '.$result['lessons'].'; завдань: '.$result['tasks']);
            $this->line('Почніть показ планування із завдання «'.$result['starter'].'».');
            $this->line('Перевірений горизонт планування: '.$result['planning_start'].' — '.$result['planning_end']);

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->error($message);
                }
            }
        } catch (ScheduleWorkbookParserException $exception) {
            $this->error($exception->getMessage());
        } catch (Throwable $exception) {
            // Do not report SQL bindings, credentials, password input, or stack traces.
            $this->error($exception::class === RuntimeException::class ? $exception->getMessage()
                : 'Успіх підготовки не підтверджено. Перевірте стан цільового акаунта та конфігурацію БД перед повторним запуском.');
        } finally {
            $password = $confirmation = null;
            if (is_string($snapshot) && is_file($snapshot)) {
                unlink($snapshot);
            }
        }

        return self::FAILURE;
    }
}
