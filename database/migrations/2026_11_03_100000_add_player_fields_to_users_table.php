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
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('member')->index();
            $table->string('programme', 40)->nullable();
            $table->string('level', 20)->nullable();
            $table->string('handedness', 10)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_active')->default(true);

            $table->float('singles_rating')->default(1500)->index();
            $table->float('doubles_rating')->default(1500)->index();
            $table->float('singles_peak')->default(1500);
            $table->float('doubles_peak')->default(1500);
            $table->unsignedInteger('singles_played')->default(0);
            $table->unsignedInteger('doubles_played')->default(0);
            $table->unsignedInteger('singles_wins')->default(0);
            $table->unsignedInteger('doubles_wins')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // SQLite cannot drop an indexed column, so drop the indexes first.
            $table->dropIndex(['role']);
            $table->dropIndex(['singles_rating']);
            $table->dropIndex(['doubles_rating']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'role',
                'programme',
                'level',
                'handedness',
                'bio',
                'is_active',
                'singles_rating',
                'doubles_rating',
                'singles_peak',
                'doubles_peak',
                'singles_played',
                'doubles_played',
                'singles_wins',
                'doubles_wins',
            ]);
        });
    }
};
