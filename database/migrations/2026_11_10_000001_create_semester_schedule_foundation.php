<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Candidate keys let new composite FKs enforce ownership without changing legacy FKs.
        foreach (['academic_periods', 'subjects', 'teachers', 'schedule_import_batches'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unique(['id', 'user_id'], $name.'_schedule_owner_unique'));
        }

        Schema::create('semester_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_period_id');
            $table->string('group_name');
            $table->date('parity_anchor_on');
            $table->string('first_week_parity', 16);
            $table->timestamps();
            $table->unique(['user_id', 'academic_period_id'], 'semester_schedule_period_unique');
            $table->unique(['id', 'user_id'], 'semester_schedule_owner_unique');
            $table->foreign(['academic_period_id', 'user_id'], 'semester_schedule_period_owner_fk')
                ->references(['id', 'user_id'])->on('academic_periods')->restrictOnDelete();
        });

        Schema::create('weekly_lesson_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('semester_schedule_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('weekday');
            $table->string('week_pattern', 16);
            $this->lessonColumns($table);
            $table->unsignedBigInteger('source_import_batch_id')->nullable();
            $table->timestamps();
            $table->foreign(['semester_schedule_id', 'user_id'], 'weekly_rule_schedule_owner_fk')
                ->references(['id', 'user_id'])->on('semester_schedules')->cascadeOnDelete();
            $table->foreign(['source_import_batch_id', 'user_id'], 'weekly_rule_import_owner_fk')
                ->references(['id', 'user_id'])->on('schedule_import_batches')->restrictOnDelete();
            $table->unique(['id', 'semester_schedule_id'], 'weekly_rule_schedule_unique');
            $table->unique(['semester_schedule_id', 'weekday', 'week_pattern', 'pair_number'], 'weekly_rule_pair_unique');
            $table->index(['semester_schedule_id', 'weekday', 'week_pattern'], 'weekly_rule_lookup_index');
        });

        Schema::create('schedule_date_exceptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('semester_schedule_id');
            $table->unsignedBigInteger('user_id');
            $table->date('occurs_on');
            $table->string('kind', 24);
            $table->unsignedTinyInteger('copied_weekday')->nullable();
            $table->timestamps();
            $table->foreign(['semester_schedule_id', 'user_id'], 'date_exception_schedule_owner_fk')
                ->references(['id', 'user_id'])->on('semester_schedules')->cascadeOnDelete();
            $table->unique(['semester_schedule_id', 'occurs_on'], 'date_exception_date_unique');
            $table->unique(['id', 'semester_schedule_id', 'user_id'], 'date_exception_owner_unique');
        });

        Schema::create('schedule_date_exception_lessons', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_date_exception_id');
            $table->unsignedBigInteger('semester_schedule_id');
            $table->unsignedBigInteger('user_id');
            $this->lessonColumns($table);
            $table->unsignedBigInteger('source_weekly_rule_id')->nullable();
            $table->timestamps();
            $table->foreign(['schedule_date_exception_id', 'semester_schedule_id', 'user_id'], 'snapshot_exception_owner_fk')
                ->references(['id', 'semester_schedule_id', 'user_id'])->on('schedule_date_exceptions')->cascadeOnDelete();
            $table->foreign(['source_weekly_rule_id', 'semester_schedule_id'], 'snapshot_source_schedule_fk')
                ->references(['id', 'semester_schedule_id'])->on('weekly_lesson_rules')->restrictOnDelete();
            $table->unique(['schedule_date_exception_id', 'pair_number'], 'snapshot_pair_unique');
        });

        DB::statement("ALTER TABLE semester_schedules
            ADD CONSTRAINT semester_schedule_parity_check CHECK (first_week_parity IN ('numerator', 'denominator')),
            ADD CONSTRAINT semester_schedule_anchor_check CHECK (WEEKDAY(parity_anchor_on) = 0)");
        DB::statement("ALTER TABLE weekly_lesson_rules
            ADD CONSTRAINT weekly_rule_weekday_check CHECK (weekday BETWEEN 1 AND 5),
            ADD CONSTRAINT weekly_rule_pattern_check CHECK (week_pattern IN ('all', 'numerator', 'denominator'))");
        DB::statement("ALTER TABLE schedule_date_exceptions
            ADD CONSTRAINT date_exception_kind_check CHECK (kind IN ('replacement', 'working_saturday')),
            ADD CONSTRAINT date_exception_copy_check CHECK (
                (kind = 'replacement' AND copied_weekday IS NULL) OR
                (kind = 'working_saturday' AND copied_weekday IS NOT NULL AND copied_weekday BETWEEN 1 AND 5 AND WEEKDAY(occurs_on) = 5))");
        foreach (['weekly_lesson_rules' => 'weekly_rule', 'schedule_date_exception_lessons' => 'snapshot'] as $name => $prefix) {
            DB::statement("ALTER TABLE {$name}
                ADD CONSTRAINT {$prefix}_pair_check CHECK (pair_number > 0),
                ADD CONSTRAINT {$prefix}_time_check CHECK (
                    starts_at_local >= '00:00:00' AND starts_at_local < ends_at_local AND ends_at_local < '24:00:00')");
        }
    }

    private function lessonColumns(Blueprint $table): void
    {
        $table->unsignedTinyInteger('pair_number');
        $table->string('pair_label', 32)->nullable();
        $table->time('starts_at_local');
        $table->time('ends_at_local');
        $table->unsignedBigInteger('subject_id');
        $table->unsignedBigInteger('teacher_id')->nullable();
        $table->string('room', 100)->nullable();
        $table->foreign(['subject_id', 'user_id'], $table->getTable().'_subject_owner_fk')
            ->references(['id', 'user_id'])->on('subjects')->restrictOnDelete();
        $table->foreign(['teacher_id', 'user_id'], $table->getTable().'_teacher_owner_fk')
            ->references(['id', 'user_id'])->on('teachers')->restrictOnDelete();
    }

    public function down(): void
    {
        // Never silently drop a semester or a frozen day snapshot.
        if (DB::table('semester_schedules')->exists()) {
            throw new RuntimeException('Semester schedules exist; use a forward migration.');
        }
        Schema::dropIfExists('schedule_date_exception_lessons');
        Schema::dropIfExists('schedule_date_exceptions');
        Schema::dropIfExists('weekly_lesson_rules');
        Schema::dropIfExists('semester_schedules');
        foreach (['academic_periods', 'subjects', 'teachers', 'schedule_import_batches'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropUnique($name.'_schedule_owner_unique'));
        }
    }
};
