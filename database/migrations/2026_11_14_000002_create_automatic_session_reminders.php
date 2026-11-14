<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('session_reminder_in_app_enabled')->default(false);
            $table->boolean('session_reminder_email_enabled')->default(false);
            $table->unsignedSmallInteger('session_reminder_lead_minutes')->default(10);
        });
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_positive_session_reminder_lead CHECK (session_reminder_lead_minutes > 0)');

        // Separate automatic origin; manual reminders and their receipts retain their existing semantics.
        Schema::create('automatic_session_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('study_session_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 10);
            $table->unsignedSmallInteger('lead_minutes');
            $table->dateTime('session_starts_at');
            $table->dateTime('trigger_at');
            $table->string('status', 12)->default('scheduled');
            $table->uuid('notification_id')->nullable()->unique();
            $table->foreign('notification_id')->references('id')->on('notifications')->nullOnDelete();
            $table->timestamps();
            $table->unique(['study_session_id', 'channel'], 'automatic_session_channel_unique');
            $table->index(['status', 'trigger_at', 'id'], 'automatic_session_due_index');
            $table->index(['user_id', 'channel', 'status'], 'automatic_session_owner_index');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE automatic_session_reminders
                ADD CONSTRAINT automatic_session_valid_channel CHECK (channel IN ('in_app', 'email')),
                ADD CONSTRAINT automatic_session_valid_status CHECK (status IN ('scheduled', 'queued', 'delivered', 'cancelled', 'suppressed')),
                ADD CONSTRAINT automatic_session_valid_lead CHECK (lead_minutes > 0 AND trigger_at < session_starts_at)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('automatic_session_reminders');
        DB::statement('ALTER TABLE users DROP CHECK users_positive_session_reminder_lead');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['session_reminder_in_app_enabled', 'session_reminder_email_enabled', 'session_reminder_lead_minutes']);
        });
    }
};
