<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('semester_schedules', fn (Blueprint $table) => $table->unsignedBigInteger('management_revision')->default(0));
        Schema::table('weekly_lesson_rules', function (Blueprint $table) {
            $table->boolean('is_manually_managed')->default(false);
        });
        DB::statement('ALTER TABLE weekly_lesson_rules ADD CONSTRAINT weekly_rule_manual_check CHECK (is_manually_managed IN (0, 1))');
        Schema::create('semester_schedule_suppressed_slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('semester_schedule_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedTinyInteger('weekday');
            $table->unsignedTinyInteger('pair_number');
            $table->string('week_pattern', 16);
            $table->timestamps();
            $table->unique(['semester_schedule_id', 'weekday', 'pair_number', 'week_pattern'], 'semester_suppressed_slot_unique');
            $table->foreign(['semester_schedule_id', 'user_id'], 'semester_suppression_schedule_owner_fk')
                ->references(['id', 'user_id'])->on('semester_schedules')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE semester_schedule_suppressed_slots
            ADD CONSTRAINT semester_suppression_weekday_check CHECK (weekday BETWEEN 1 AND 5),
            ADD CONSTRAINT semester_suppression_pair_check CHECK (pair_number > 0),
            ADD CONSTRAINT semester_suppression_pattern_check CHECK (week_pattern IN ('all', 'numerator', 'denominator'))");
    }

    public function down(): void
    {
        if (DB::table('semester_schedules')->where('management_revision', '>', 0)->exists()
            || DB::table('semester_schedule_suppressed_slots')->exists()
            || DB::table('weekly_lesson_rules')->where('is_manually_managed', true)->exists()) {
            throw new RuntimeException('Manual schedule protections exist; use a forward migration.');
        }
        Schema::dropIfExists('semester_schedule_suppressed_slots');
        DB::statement('ALTER TABLE weekly_lesson_rules DROP CHECK weekly_rule_manual_check');
        Schema::table('weekly_lesson_rules', fn (Blueprint $table) => $table->dropColumn('is_manually_managed'));
        Schema::table('semester_schedules', fn (Blueprint $table) => $table->dropColumn('management_revision'));
    }
};
