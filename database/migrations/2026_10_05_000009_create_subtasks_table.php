<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subtasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->dateTime('deadline_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_id', 'position']);
            $table->index(['task_id', 'status', 'deadline_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE subtasks
                ADD CONSTRAINT subtasks_positive_position
                    CHECK (position > 0),
                ADD CONSTRAINT subtasks_valid_status
                    CHECK (status IN ('pending', 'completed')),
                ADD CONSTRAINT subtasks_positive_estimate
                    CHECK (estimated_minutes IS NULL OR estimated_minutes > 0),
                ADD CONSTRAINT subtasks_completion_consistency
                    CHECK ((status = 'completed' AND completed_at IS NOT NULL) OR (status = 'pending' AND completed_at IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('subtasks');
    }
};
