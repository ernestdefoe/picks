<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists(
    'picks_user_scores',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('season_id')->nullable();
        $table->unsignedInteger('week_id')->nullable();
        $table->unsignedInteger('total_points')->default(0);
        $table->unsignedInteger('total_picks')->default(0);
        $table->unsignedInteger('correct_picks')->default(0);
        $table->decimal('accuracy', 5, 2)->default(0.00);
        $table->unsignedInteger('previous_rank')->nullable();
        $table->timestamps();

        // Prevents duplicate week- and season-scope rows (both columns NOT
        // NULL). It does NOT dedupe the all-time scope (season_id=NULL,
        // week_id=NULL) because MySQL/MariaDB treat NULL != NULL in a unique
        // index — and a NULL-safe unique key isn't portable across the DB
        // engines Flarum supports. That race is instead serialized at the
        // application layer: ScoreAggregator::upsertScore() takes a per-user,
        // per-scope atomic lock around its read-then-write.
        $table->unique(['user_id', 'season_id', 'week_id']);

        $table->foreign('user_id')
            ->references('id')
            ->on('users')
            ->onDelete('cascade');

        $table->foreign('season_id')
            ->references('id')
            ->on('picks_seasons')
            ->onDelete('cascade');

        $table->foreign('week_id')
            ->references('id')
            ->on('picks_weeks')
            ->onDelete('cascade');
    }
);
