<?php

namespace App\Jobs;

use App\Services\Auth\PasswordManagementService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            try {
                Log::warning('password.delivery_failed');
            } catch (Throwable) {
            }
        }
    }
}
