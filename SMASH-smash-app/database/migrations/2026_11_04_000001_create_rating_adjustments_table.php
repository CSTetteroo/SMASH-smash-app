<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Audited manual rating changes (placements, corrections, penalties). Ratings are never edited
     * directly: the rating engine replays matches and adjustments in date order.
     */
    public function up(): void
    {
        Schema::create('rating_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('discipline', 20);
            // Rating points added; negative takes points off. Never zero.
            $table->float('amount');
            $table->string('reason', 160);
            // Counts before every match played at or after this moment.
            $table->dateTime('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'discipline', 'effective_at']);
            $table->index('effective_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rating_adjustments');
    }
};
