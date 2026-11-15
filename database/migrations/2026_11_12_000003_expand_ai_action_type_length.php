<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ai_pending_actions MODIFY COLUMN type VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('AI action identity length is forward-only. Use a forward compatibility migration.');
    }
};
