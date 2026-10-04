<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * The games a week's Confidence contest is played on, in order.
 *
 * Its own table rather than a flag on picks_events: a game is in the full
 * board by being in the week, and in the Confidence contest by being CHOSEN,
 * and the choice has an order. Position 1 is the tiebreaker game.
 */
return Migration::createTableIfNotExists(
    'picks_confidence_games',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('week_id');
        $table->unsignedInteger('event_id');
        $table->unsignedSmallInteger('position');
        $table->timestamps();

        $table->unique(['week_id', 'event_id']);

        $table->foreign('week_id')->references('id')->on('picks_weeks')->onDelete('cascade');
        $table->foreign('event_id')->references('id')->on('picks_events')->onDelete('cascade');
    }
);
