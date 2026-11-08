<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // One atomic MySQL ALTER: no unguarded interval and no row rewriting.
        DB::statement(<<<'SQL'
            ALTER TABLE tasks
                DROP CHECK tasks_valid_status,
                DROP CHECK tasks_completion_consistency,
                ADD CONSTRAINT tasks_valid_status
                    CHECK (status IN ('pending', 'completed', 'missed')),
                ADD CONSTRAINT tasks_completion_consistency
                    CHECK ((status = 'completed' AND completed_at IS NOT NULL)
                        OR (status IN ('pending', 'missed') AND completed_at IS NULL))
            SQL);
    }

    public function down(): void
    {
        // MySQL DDL implicitly commits. Never erase or reinterpret unfinished history.
        throw new RuntimeException('Stage 16A is forward-only. Restore compatibility with a new forward migration.');
    }
};
