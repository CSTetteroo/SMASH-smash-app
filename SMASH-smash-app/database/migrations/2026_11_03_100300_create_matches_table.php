<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->string('discipline', 20);
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('tournament_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('round')->nullable();
            $table->unsignedSmallInteger('bracket_position')->nullable();
            $table->foreignId('entry_a_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->foreignId('entry_b_id')->nullable()->constrained('tournament_entries')->nullOnDelete();
            $table->unsignedTinyInteger('best_of')->default(3);
            $table->unsignedTinyInteger('points_per_game')->default(21);
            $table->boolean('is_rated')->default(true);
            $table->unsignedTinyInteger('court')->nullable();
            $table->string('venue')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('played_at')->nullable();
            $table->string('winning_side', 1)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'played_at']);
            $table->index(['status', 'scheduled_at']);
            $table->index(['tournament_id', 'round', 'bracket_position']);
            $table->index('entry_a_id');
            $table->index('entry_b_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
