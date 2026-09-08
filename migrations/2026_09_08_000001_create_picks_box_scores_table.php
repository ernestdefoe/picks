<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * What happened in a game, beyond who won.
 *
 * 🚨 One row per game holding a NORMALISED document, not a table of columns per
 * statistic. A box score is not a fixed set of numbers — the categories differ
 * between an FBS game and a lower-division one, and a game whose feed carried
 * no defensive statistics simply has none — so a column per statistic would be
 * a migration every time a feed changed and a table of nulls the rest of the
 * time.
 *
 * And the shape stored is OURS rather than the provider's. Normalising on the
 * way in means every reader sees the same document however
 * collegefootballdata.com decides to arrange its JSON next season.
 *
 * 🚨 `fetched_at` is separate from `updated_at`: one says when this was true,
 * which is what a screen showing it has to be able to say out loud; the other
 * says when the row was last written, which changes when nothing about the game
 * did.
 */
return Migration::createTableIfNotExists(
    'picks_box_scores',
    function (Blueprint $table) {
        $table->increments('id');

        // One box score per game: a second fetch replaces the first.
        $table->unsignedInteger('event_id')->unique();

        $table->longText('payload');
        $table->dateTime('fetched_at');
        $table->timestamps();

        $table->foreign('event_id')
            ->references('id')
            ->on('picks_events')
            ->onDelete('cascade');
    }
);
