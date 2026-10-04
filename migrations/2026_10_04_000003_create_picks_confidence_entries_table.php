<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A member's entry for a week's Confidence contest beyond the picks
 * themselves: the tiebreaker, a guess at the total points in the week's first
 * game.
 */
return Migration::createTableIfNotExists(
    'picks_confidence_entries',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('week_id');
        $table->unsignedSmallInteger('tiebreaker_total')->nullable();
        $table->timestamps();

        $table->unique(['user_id', 'week_id']);

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('week_id')->references('id')->on('picks_weeks')->onDelete('cascade');
    }
);
