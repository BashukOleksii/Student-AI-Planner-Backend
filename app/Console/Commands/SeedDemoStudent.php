<?php

namespace App\Console\Commands;

use App\Exceptions\DemoEnvironmentException;
use App\Services\Demo\DemoDatabaseGuard;
use App\Services\Demo\DemoStudentService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class SeedDemoStudent extends Command
{
    protected $signature = 'demo:seed-student {--inspect : Read local database identity without creating data}';

    protected $description = 'Create one uniquely identified synthetic student in an explicitly approved local MySQL database';

    public function handle(DemoDatabaseGuard $guard, DemoStudentService $students): int
    {
        try {
            if (filter_var(getenv('CI') ?: false, FILTER_VALIDATE_BOOL) || filter_var(getenv('GITHUB_ACTIONS') ?: false, FILTER_VALIDATE_BOOL)) {
                throw new DemoEnvironmentException('Interactive demo credentials must not be generated in CI.');
            }
            $identity = $guard->inspect(! $this->option('inspect'));
            $this->table(['Environment', 'Connection', 'Database', 'Server UUID', 'Server hostname', 'Port'], [array_values($identity)]);
            if ($this->option('inspect')) {
                $this->line('Read-only inspection. No demo account was created.');

                return self::SUCCESS;
            }
            if (! $this->input->isInteractive() || ! $this->confirm('Create exactly one NEW synthetic demo account in this approved database? Existing users will not be changed.', false)) {
                $this->line('Cancelled. No demo account was created.');

                return self::FAILURE;
            }
            $password = Str::random(32);
            $result = $students->create($password);
        } catch (DemoEnvironmentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Demo generation failed. No credentials were printed.');

            return self::FAILURE;
        }
        $this->line('DEMO STUDENT CREATED');
        $this->line('Email: '.$result['user']->email);
        $this->line('Password: '.$password);
        unset($password);
        $this->line('Frontend: http://localhost:5173');
        $this->table(['Dataset', 'Count / ID'], collect($result['summary'])->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->table(['Manual scenario', 'Date / owned ID'], collect($result['scenarios'])->map(fn ($value, $key) => [$key, $value])->values()->all());
        $this->line('Command: php artisan demo:seed-student');
        $this->line('Inspect: today/week calendar, semester exceptions and working Saturday; tasks/subtasks; plan remaining work; review recovery without auto-confirming; reminders/inbox; progress comparisons; sample AI conversations.');
        $this->line('Recovery: one pending academic-conflict review. It expires quickly; use Refresh if needed.');
        $this->line('Unknown outcomes: none are preloaded because they block actionable recovery. To explore clarification, leave an upcoming session undecided after it ends, then run the normal detector.');

        return self::SUCCESS;
    }
}
