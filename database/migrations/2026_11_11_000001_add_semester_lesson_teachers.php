<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LINKS = [
        'subject_teacher' => ['subjects', 'subject_id', 'subject'],
        'weekly_lesson_rule_teacher' => ['weekly_lesson_rules', 'weekly_lesson_rule_id', 'weekly'],
        'schedule_date_exception_lesson_teacher' => ['schedule_date_exception_lessons', 'schedule_date_exception_lesson_id', 'snapshot'],
    ];

    public function up(): void
    {
        foreach (['weekly_lesson_rules', 'schedule_date_exception_lessons'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique(['id', 'user_id'], $table.'_teacher_owner_unique'));
        }
        foreach (self::LINKS as $pivot => [$parent, $key, $prefix]) {
            Schema::create($pivot, function (Blueprint $table) use ($parent, $key, $prefix): void {
                $table->unsignedBigInteger($key);
                $table->unsignedBigInteger('teacher_id');
                $table->unsignedBigInteger('user_id');
                $table->primary([$key, 'teacher_id'], $prefix.'_teachers_primary');
                $table->foreign([$key, 'user_id'], $prefix.'_teachers_parent_owner_fk')
                    ->references(['id', 'user_id'])->on($parent)->cascadeOnDelete();
                $teacher = $table->foreign(['teacher_id', 'user_id'], $prefix.'_teachers_teacher_owner_fk')
                    ->references(['id', 'user_id'])->on('teachers');
                // Catalog-only links may disappear with an explicitly deleted teacher.
                // Recurring rules and frozen snapshots must retain their teachers.
                if ($parent === 'subjects') {
                    $teacher->cascadeOnDelete();
                } else {
                    $teacher->restrictOnDelete();
                }
            });
        }
        // Backfill links only; historical import_baseline JSON and legacy Lessons stay intact.
        foreach (array_slice(self::LINKS, 1, null, true) as $pivot => [$parent, $key]) {
            DB::table($pivot)->insertUsing([$key, 'teacher_id', 'user_id'],
                DB::table($parent)->select('id', 'teacher_id', 'user_id')->whereNotNull('teacher_id'));
        }
        $links = DB::table('weekly_lesson_rules')->select('subject_id', 'teacher_id', 'user_id')->whereNotNull('teacher_id')
            ->union(DB::table('schedule_date_exception_lessons')->select('subject_id', 'teacher_id', 'user_id')->whereNotNull('teacher_id'));
        DB::table('subject_teacher')->insertUsing(['subject_id', 'teacher_id', 'user_id'], $links);
    }

    public function down(): void
    {
        foreach (self::LINKS as $pivot => [$parent, $key]) {
            if (DB::table($pivot)->exists()) {
                throw new RuntimeException('Semester teacher relationships exist; use a forward migration.');
            }
        }
        foreach (array_reverse(array_keys(self::LINKS)) as $pivot) {
            Schema::dropIfExists($pivot);
        }
        foreach (['weekly_lesson_rules', 'schedule_date_exception_lessons'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropUnique($table.'_teacher_owner_unique'));
        }
    }
};
