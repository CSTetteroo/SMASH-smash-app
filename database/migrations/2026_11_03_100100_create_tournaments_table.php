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
        Schema::create('tournaments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('discipline', 20);
            $table->string('format', 30);
            $table->string('status', 20)->default('draft')->index();
            $table->string('venue')->nullable();
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->unsignedSmallInteger('max_entries')->nullable();
            $table->unsignedTinyInteger('best_of')->default(3);
            $table->unsignedTinyInteger('points_per_game')->default(21);
            $table->boolean('is_rated')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
