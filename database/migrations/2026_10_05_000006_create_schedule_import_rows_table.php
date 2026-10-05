<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('status', 16);
            $table->json('raw_data');
            $table->json('normalized_data')->nullable();
            $table->json('validation_errors')->nullable();
            $table->char('fingerprint', 64)->nullable();
            $table->timestamps();

            $table->unique(['schedule_import_batch_id', 'row_number']);
            $table->index(['schedule_import_batch_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE schedule_import_rows
                ADD CONSTRAINT schedule_import_rows_positive_row_number
                    CHECK (`row_number` > 0),
                ADD CONSTRAINT schedule_import_rows_valid_status
                    CHECK (status IN ('valid', 'invalid', 'duplicate'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_import_rows');
    }
};
