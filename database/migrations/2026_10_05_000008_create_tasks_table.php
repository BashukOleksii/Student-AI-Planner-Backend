<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedTinyInteger('priority')->default(2);
            $table->unsignedSmallInteger('estimated_minutes')->nullable();
            $table->dateTime('deadline_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status', 'deadline_at']);
            $table->index(['user_id', 'subject_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tasks
                ADD CONSTRAINT tasks_valid_status
                    CHECK (status IN ('pending', 'completed')),
                ADD CONSTRAINT tasks_valid_priority
                    CHECK (priority BETWEEN 1 AND 3),
                ADD CONSTRAINT tasks_positive_estimate
                    CHECK (estimated_minutes IS NULL OR estimated_minutes > 0),
                ADD CONSTRAINT tasks_completion_consistency
                    CHECK ((status = 'completed' AND completed_at IS NOT NULL) OR (status = 'pending' AND completed_at IS NULL))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
