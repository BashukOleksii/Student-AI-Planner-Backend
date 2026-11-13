<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoDatabaseGuard;
use App\Services\Demo\PlanningDemoService;
use Illuminate\Console\Command;
use Throwable;

class SeedPlanningDemo extends Command
{
    protected $signature = 'planning:demo-seed {--user= : Exact name, or id:N} {--dry-run : Inspect proposed fixtures without writing}';

    protected $description = 'Create marked planning examples for one verified existing local user, preserving all settings and real records';

    public function handle(DemoDatabaseGuard $guard, PlanningDemoService $demo): int
    {
        try {
            $identity = $guard->inspect(false);
            $selector = $this->option('user');
            if (! is_string($selector) || $selector === '') {
                $this->error('Вкажіть --user з точним ім’ям або id:N.');

                return self::FAILURE;
            }
            $owner = $demo->resolve($selector);
            $this->line('Локальна БД: '.$identity['database'].'; власник: '.$owner->name.'; ID: '.$owner->id.'; timezone: '.$owner->timezone);
            $dryRun = (bool) $this->option('dry-run');
            if (! $dryRun && (! $this->input->isInteractive() || ! $this->confirm('Створити лише [DEMO-PLANNING] завдання та валідні сесії для цього власника? Налаштування й існуючі записи не змінюються.', false))) {
                $this->line('Скасовано. Дані не змінено.');

                return self::FAILURE;
            }
            $result = $demo->run($owner, $dryRun);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $this->line($dryRun ? 'DRY RUN: записів не створено.' : 'Готово. Збережіть фактичні IDs із результату; повторний запуск зберігає наявні DEMO-записи.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Операцію не виконано; транзакцію відкочено. Перевірте локальне середовище, точного власника й DEMO-маркери. '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
