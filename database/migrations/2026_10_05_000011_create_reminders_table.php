<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subtask_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('study_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message', 500)->nullable();
            $table->dateTime('trigger_at');
            $table->string('anchor', 32)->nullable();
            $table->smallInteger('offset_minutes')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->dateTime('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'trigger_at']);
            $table->index(['user_id', 'status', 'trigger_at']);
        });

        // MySQL rejects CHECKs involving SET NULL target FKs (3823). Target count,
        // anchor compatibility, ownership, and purge reconciliation belong to Services.
        DB::statement(<<<'SQL'
            ALTER TABLE reminders
                ADD CONSTRAINT reminders_valid_status
                    CHECK (status IN ('scheduled', 'sent', 'cancelled')),
                ADD CONSTRAINT reminders_valid_anchor
                    CHECK (anchor IS NULL OR anchor IN ('task_deadline', 'subtask_deadline', 'session_start')),
                ADD CONSTRAINT reminders_anchor_offset_pair
                    CHECK ((anchor IS NULL AND offset_minutes IS NULL) OR (anchor IS NOT NULL AND offset_minutes IS NOT NULL)),
                ADD CONSTRAINT reminders_sent_consistency
                    CHECK ((status = 'sent' AND sent_at IS NOT NULL) OR (status IN ('scheduled', 'cancelled') AND sent_at IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
    }
};
