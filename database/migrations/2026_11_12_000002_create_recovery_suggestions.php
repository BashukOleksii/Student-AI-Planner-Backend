<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_suggestions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // One active review per owner, including advisory reviews. Null frees the slot on resolution.
            $table->unsignedBigInteger('active_owner_id')->nullable()->unique();
            $table->char('dedupe_key', 64)->unique();
            $table->char('source_hash', 64);
            $table->char('need_hash', 64);
            // Logical immutable refresh lineage, server assigned and owner checked. No self-cascade on account purge.
            $table->uuid('refreshed_from_id')->nullable()->unique();
            $table->string('kind', 32);
            $table->string('status', 16)->default('pending');
            $table->date('start_date');
            $table->date('end_date');
            $table->json('review');
            $table->longText('protected_token')->nullable();
            $table->json('receipt')->nullable();
            $table->dateTime('expires_at', 6);
            $table->dateTime('resolved_at', 6)->nullable();
            $table->timestamps(6);
            $table->index(['user_id', 'status', 'created_at'], 'recovery_suggestions_owner_feed');
            $table->index(['user_id', 'source_hash', 'created_at'], 'recovery_suggestions_owner_source');
            $table->index(['user_id', 'need_hash'], 'recovery_suggestions_owner_need');
        });
        DB::statement(<<<'SQL'
            ALTER TABLE recovery_suggestions
                ADD CONSTRAINT recovery_suggestions_kind CHECK (kind IN ('create_plan', 'replan', 'decision_required', 'warning')),
                ADD CONSTRAINT recovery_suggestions_status CHECK (status IN ('pending', 'applied', 'rejected', 'expired', 'invalidated')),
                ADD CONSTRAINT recovery_suggestions_active CHECK (
                    (status = 'pending' AND active_owner_id IS NOT NULL AND active_owner_id = user_id)
                    OR (status <> 'pending' AND active_owner_id IS NULL)),
                ADD CONSTRAINT recovery_suggestions_receipt CHECK (
                    (status = 'applied' AND receipt IS NOT NULL) OR (status <> 'applied' AND receipt IS NULL)),
                ADD CONSTRAINT recovery_suggestions_resolution CHECK (
                    (status = 'pending' AND resolved_at IS NULL) OR (status <> 'pending' AND resolved_at IS NOT NULL))
            SQL);
        Schema::create('recovery_scan_states', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary('user_id');
            $table->char('last_source_hash', 64)->nullable();
            $table->string('last_outcome', 32)->nullable();
            $table->dateTime('last_scanned_at', 6)->nullable()->index();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Recovery suggestion history is forward-only. Use a new compatibility migration.');
    }
};
