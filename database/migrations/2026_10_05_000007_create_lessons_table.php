<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_import_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('replaces_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('type', 32)->default('lecture');
            $table->string('room', 100)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('status', 20)->default('active');
            $table->char('import_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status', 'starts_at']);
            $table->index(['academic_period_id', 'starts_at']);
            $table->unique('replaces_lesson_id');
            $table->unique(['user_id', 'academic_period_id', 'import_fingerprint']);
        });

        // Self-replacement is a future Service invariant: MySQL rejects a CHECK
        // referencing AUTO_INCREMENT id (3818) or the self SET NULL FK (3823).
        DB::statement(<<<'SQL'
            ALTER TABLE lessons
                ADD CONSTRAINT lessons_valid_interval
                    CHECK (starts_at < ends_at),
                ADD CONSTRAINT lessons_valid_status
                    CHECK (status IN ('active', 'cancelled', 'replaced')),
                ADD CONSTRAINT lessons_valid_type
                    CHECK (type IN ('lecture', 'practical', 'laboratory', 'seminar', 'consultation', 'exam', 'other')),
                ADD CONSTRAINT lessons_import_requires_period
                    CHECK (import_fingerprint IS NULL OR academic_period_id IS NOT NULL)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
