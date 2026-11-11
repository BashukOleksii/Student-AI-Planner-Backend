<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['weekly_lesson_rules', 'schedule_date_exception_lessons'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('lesson_type', 16)->nullable());
        }
        foreach (['weekly_lesson_rules' => 'weekly_rule', 'schedule_date_exception_lessons' => 'snapshot'] as $table => $prefix) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$prefix}_lesson_type_check CHECK (lesson_type IS NULL OR lesson_type IN ('lecture', 'laboratory', 'practical', 'seminar', 'consultation', 'exam', 'other'))");
        }
    }

    public function down(): void
    {
        foreach (['weekly_lesson_rules' => 'weekly_rule', 'schedule_date_exception_lessons' => 'snapshot'] as $table => $prefix) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$prefix}_lesson_type_check");
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('lesson_type'));
        }
    }
};
