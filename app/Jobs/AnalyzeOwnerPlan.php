<?php

namespace App\Jobs;

use App\Exceptions\RecoverySuggestionException;
use App\Services\Planning\RecoveryCheckTriggerService;
use App\Services\Planning\RecoverySuggestionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AnalyzeOwnerPlan implements ShouldQueue
{
    use Queueable;

    // Releases during a sustained edit burst do not consume a finite attempts budget.
    public int $tries = 0;

    public int $timeout = 60;

    public int $backoff = 30;

    public int $deliveryDeadline;

    public function __construct(public int $ownerId, public string $token)
    {
        $this->deliveryDeadline = CarbonImmutable::now('UTC')->getTimestamp() + 600;
    }

    public function retryUntil(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.$this->deliveryDeadline);
    }

    public function handle(RecoveryCheckTriggerService $trigger, RecoverySuggestionService $suggestions): void
    {
        $delay = $trigger->start($this->ownerId, $this->token);
        if ($delay === null) {
            return;
        }
        if ($delay > 0) {
            $this->release($delay);

            return;
        }
        try {
            // Same owner-first detector and source/need deduplication as Scheduler. Never a writer of sessions.
            $suggestions->detect($this->ownerId, 7);
        } catch (Throwable $exception) {
            $code = $exception instanceof RecoverySuggestionException && $exception->errorCode === 'analysis_limit_exceeded'
                ? 'analysis_limit_exceeded' : 'analysis_failed';
            $trigger->fail($this->ownerId, $code, $this->token);
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(RecoveryCheckTriggerService::class)->fail($this->ownerId, 'analysis_failed', $this->token);
    }
}
