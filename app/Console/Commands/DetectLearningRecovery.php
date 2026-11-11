<?php

namespace App\Console\Commands;

use App\Services\Planning\RecoverySuggestionService;
use Illuminate\Console\Command;

class DetectLearningRecovery extends Command
{
    protected $signature = 'learning:detect-recovery {--limit=10 : Maximum owners, 1–100} {--days=7 : Inclusive local horizon, 1–31} {--user= : Optional trusted owner ID}';

    protected $description = 'Detect bounded learning recovery needs and persist reviews without applying any plan';

    public function handle(RecoverySuggestionService $service): int
    {
        $limit = $this->decimal($this->option('limit'), 100);
        $days = $this->decimal($this->option('days'), 31);
        $user = $this->option('user');
        $ownerId = $user === null ? null : $this->decimal($user, PHP_INT_MAX);
        if ($limit === null || $days === null || ($user !== null && $ownerId === null)) {
            $this->error('Use positive decimal limits: owners 1–100, days 1–31, optional positive owner ID.');

            return self::INVALID;
        }
        $counts = $service->scan($limit, $days, $ownerId);
        $this->line(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function decimal(mixed $value, int $max): ?int
    {
        if (! is_string($value) || ! preg_match('/\A[1-9][0-9]*\z/', $value)
            || strlen($value) > strlen((string) $max) || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
