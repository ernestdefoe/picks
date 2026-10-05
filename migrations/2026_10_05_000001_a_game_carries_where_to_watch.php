<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Every place a game is being shown, not just the national one.
 *
 * `broadcast` beside this keeps the one channel name a sentence needs — "On
 * ABC." — and stays exactly as it was. This holds the whole listing so a board
 * can say WHERE to watch: each channel, whether it is television, a streaming
 * service or radio, which market it covers, and the feed's own watch link when
 * it sends one.
 *
 * 🚨 JSON in a TEXT column rather than a table of its own. It is read as one
 * piece, written as one piece, never queried into, and it belongs to the
 * fixture in exactly the way the rank does: a listing is a fact about the week
 * the game was played in.
 *
 * 🚨 Nothing here costs a request. It arrives in the scoreboard payload the
 * score sync already fetches — the same reason every lead-in column is on the
 * fixture.
 *
 * Created through the schema builder, so the table prefix is applied.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events') || $schema->hasColumn('picks_events', 'broadcasts')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) {
            $table->text('broadcasts')->nullable();
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events') || ! $schema->hasColumn('picks_events', 'broadcasts')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) {
            $table->dropColumn('broadcasts');
        });
    },
];
