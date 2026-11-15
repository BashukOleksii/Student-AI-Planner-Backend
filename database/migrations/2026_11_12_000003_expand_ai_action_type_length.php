<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Registered occurrence and working-Saturday action names exceed the original 32 bytes.
        // Preserve all values, CHECKs, indexes and retained receipts; no action is renamed.
        DB::statement('ALTER TABLE ai_pending_actions MODIFY COLUMN type VARCHAR(64) NOT NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('AI action identity length is forward-only. Use a forward compatibility migration.');
    }
};
