<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_period_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('uploaded');
            $table->string('original_filename');
            $table->char('file_hash', 64);
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->text('error_message')->nullable();
            $table->dateTime('committed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'academic_period_id', 'created_at'], 'schedule_import_batches_user_period_created_index');
            $table->index(['user_id', 'file_hash']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE schedule_import_batches
                ADD CONSTRAINT schedule_import_batches_valid_status
                    CHECK (status IN ('uploaded', 'validated', 'committed', 'failed', 'cancelled'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_import_batches');
    }
};
