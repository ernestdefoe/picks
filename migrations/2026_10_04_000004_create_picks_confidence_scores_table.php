<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Confidence standings, a row per member per week and per season.
 *
 * 🚨 Keyed on `scope` ('w12', 's3'), not on a nullable week_id. A unique index
 * treats NULL as different from NULL, which is how picks_user_scores came to
 * need an application-level lock to stop duplicate season rows. A string that
 * is never null lets the database refuse the duplicate itself.
 *
 * 🚨 total_points is SIGNED. With a penalty on, a bad week is a negative week,
 * and an unsigned column would turn -6 into an error or into zero.
 */
return Migration::createTableIfNotExists(
    'picks_confidence_scores',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('season_id');
        $table->unsignedInteger('week_id')->nullable();
        $table->string('scope', 20);
        $table->integer('total_points')->default(0);
        $table->unsignedInteger('total_picks')->default(0);
        $table->unsignedInteger('correct_picks')->default(0);
        $table->decimal('accuracy', 5, 2)->default(0.00);
        $table->unsignedInteger('tiebreak_diff')->nullable();
        $table->unsignedInteger('previous_rank')->nullable();
        $table->unsignedInteger('current_rank')->nullable();
        $table->timestamps();

        $table->unique(['user_id', 'scope']);
        $table->index('scope');

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('season_id')->references('id')->on('picks_seasons')->onDelete('cascade');
        $table->foreign('week_id')->references('id')->on('picks_weeks')->onDelete('cascade');
    }
);
