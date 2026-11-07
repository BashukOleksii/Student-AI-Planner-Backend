<?php

namespace App\Jobs;

use App\Services\Auth\PasswordManagementService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Queue the same encrypted work for every address; look up accounts only in the worker. */
class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(#[\SensitiveParameter] public string $email) {}

    public function handle(PasswordManagementService $service): void
    {
        try {
            $service->sendResetLink($this->email);
        } catch (Throwable) {
            // SMTP/SQL exceptions can contain credentials, addresses and tokens. Never report them.
            try {
                Log::warning('password.delivery_failed');
            } catch (Throwable) {
                // Delivery diagnostics must not expose the original exception through the worker.
            }
        }
    }
}
