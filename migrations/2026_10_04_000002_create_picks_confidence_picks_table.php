<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * A member's Confidence picks: a winner and a value, one per chosen game.
 *
 * 🚨 Separate from picks_picks on purpose. The two contests run side by side
 * on the same games, and a member can back one side on the full board and the
 * other here. Sharing a row would make every Confidence save rewrite a full
 * board pick, and the full board's scores with it.
 *
 * The (user, week, confidence) key is the database's own guarantee that no two
 * games hold the same value, whatever reaches it.
 */
return Migration::createTableIfNotExists(
    'picks_confidence_picks',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('user_id');
        $table->unsignedInteger('week_id');
        $table->unsignedInteger('event_id');
        $table->string('selected_outcome', 10);
        $table->unsignedSmallInteger('confidence');
        $table->boolean('is_correct')->nullable();
        $table->timestamps();

        $table->unique(['user_id', 'event_id']);
        $table->unique(['user_id', 'week_id', 'confidence']);
        $table->index('event_id');

        $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        $table->foreign('week_id')->references('id')->on('picks_weeks')->onDelete('cascade');
        $table->foreign('event_id')->references('id')->on('picks_events')->onDelete('cascade');
    }
);
