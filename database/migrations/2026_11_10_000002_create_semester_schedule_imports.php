<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semester_schedule_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_period_id');
            $table->string('original_filename');
            $table->char('file_hash', 64);
            $table->date('parity_anchor_on');
            $table->string('first_week_parity', 16);
            $table->string('status', 16);
            $table->json('template')->nullable();
            $table->json('review');
            $table->char('context_hash', 64)->nullable();
            $table->char('preview_token', 64);
            $table->json('receipt')->nullable();
            $table->dateTime('committed_at', 6)->nullable();
            $table->timestamps();
            $table->unique(['id', 'user_id'], 'semester_import_owner_unique');
            $table->index(['user_id', 'academic_period_id', 'id'], 'semester_import_lookup_index');
            $table->foreign(['academic_period_id', 'user_id'], 'semester_import_period_owner_fk')
                ->references(['id', 'user_id'])->on('academic_periods')->restrictOnDelete();
        });
        Schema::table('weekly_lesson_rules', function (Blueprint $table) {
            $table->unsignedBigInteger('semester_schedule_import_id')->nullable();
            $table->string('import_key', 40)->nullable();
            $table->json('import_baseline')->nullable();
            $table->unique(['semester_schedule_id', 'import_key'], 'weekly_rule_import_key_unique');
            $table->index(['semester_schedule_import_id', 'user_id'], 'weekly_rule_semester_import_owner_index');
            $table->foreign(['semester_schedule_import_id', 'user_id'], 'weekly_rule_semester_import_owner_fk')
                ->references(['id', 'user_id'])->on('semester_schedule_imports')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE semester_schedule_imports
            ADD CONSTRAINT semester_import_status_check CHECK (status IN ('ready', 'invalid', 'failed', 'committed')),
            ADD CONSTRAINT semester_import_parity_check CHECK (first_week_parity IN ('numerator', 'denominator') AND WEEKDAY(parity_anchor_on) = 0),
            ADD CONSTRAINT semester_import_receipt_check CHECK (
                (status = 'committed' AND receipt IS NOT NULL AND committed_at IS NOT NULL) OR
                (status <> 'committed' AND receipt IS NULL AND committed_at IS NULL))");
        DB::statement("ALTER TABLE weekly_lesson_rules ADD CONSTRAINT weekly_rule_semester_import_check CHECK (
            (semester_schedule_import_id IS NULL AND import_key IS NULL AND import_baseline IS NULL) OR
            (semester_schedule_import_id IS NOT NULL AND import_key IS NOT NULL AND import_baseline IS NOT NULL
                AND JSON_TYPE(import_baseline) = 'OBJECT' AND source_import_batch_id IS NULL))");
    }

    public function down(): void
    {
        if (DB::table('semester_schedule_imports')->exists()) {
            throw new RuntimeException('Semester import history exists; use a forward migration.');
        }
        DB::statement('ALTER TABLE weekly_lesson_rules DROP CHECK weekly_rule_semester_import_check');
        Schema::table('weekly_lesson_rules', function (Blueprint $table) {
            $table->dropForeign('weekly_rule_semester_import_owner_fk');
            $table->dropIndex('weekly_rule_semester_import_owner_index');
            $table->dropUnique('weekly_rule_import_key_unique');
            $table->dropColumn(['semester_schedule_import_id', 'import_key', 'import_baseline']);
        });
        Schema::dropIfExists('semester_schedule_imports');
    }
};
