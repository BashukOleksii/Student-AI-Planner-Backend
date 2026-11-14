<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No historical backfill: existing encrypted jobs remain the only intent for old deliveries.
        Schema::create('reminder_email_intents', function (Blueprint $table) {
            $table->engine('InnoDB');
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('notification_id')->unique();
            $table->foreign('notification_id')->references('id')->on('notifications')->cascadeOnDelete();
            $table->char('recipient_fingerprint', 64);
            $table->unsignedInteger('attempts')->default(0);
            $table->dateTime('next_attempt_at');
            // Queue acceptance only; never a claim of successful SMTP delivery.
            $table->dateTime('queued_at')->nullable();
            $table->timestamps();
            $table->index(['queued_at', 'next_attempt_at', 'id'], 'reminder_email_intents_due_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_email_intents');
    }
};
