<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->index('rescheduled_from_session_id', 'study_sessions_predecessor_index');
            $table->dropUnique(['rescheduled_from_session_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('study_sessions')->select('rescheduled_from_session_id')->whereNotNull('rescheduled_from_session_id')->groupBy('rescheduled_from_session_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Cannot restore one-to-one uniqueness while split history exists. Use a forward migration.');
        }
        Schema::table('study_sessions', function (Blueprint $table) {
            $table->unique('rescheduled_from_session_id');
            $table->dropIndex('study_sessions_predecessor_index');
        });
    }
};
