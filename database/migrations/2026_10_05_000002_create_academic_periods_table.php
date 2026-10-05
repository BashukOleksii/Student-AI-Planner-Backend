<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('education_institution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();

            $table->index(['user_id', 'starts_on', 'ends_on']);
            $table->unique(['user_id', 'name', 'starts_on']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE academic_periods
                ADD CONSTRAINT academic_periods_valid_date_range
                    CHECK (starts_on <= ends_on)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('academic_periods');
    }
};
