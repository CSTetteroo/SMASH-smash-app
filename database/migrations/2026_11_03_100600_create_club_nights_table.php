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
        Schema::create('club_nights', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('kind', 20)->default('social');
            $table->text('description')->nullable();
            $table->string('venue');
            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->index();
            $table->unsignedTinyInteger('courts')->default(4);
            $table->unsignedSmallInteger('capacity')->default(24);
            $table->string('level', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('club_nights');
    }
};
