<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * What a game looks like BEFORE it is played.
 *
 * The live columns beside these describe a game in progress. These describe the
 * one thing a fixture could not say until now: why it is worth watching. Who is
 * ranked, what each side has done so far, where it is being played and who is
 * showing it.
 *
 * 🚨 The RANK IS STORED ON THE GAME, not on the team, and that is the whole
 * design decision here. A rank is a fact about a week — Alabama were 4th when
 * they played Wisconsin and 12th a month later — so a rank kept on the team
 * rewrites history every Sunday: last month's game thread would silently
 * relabel itself, and a final score sitting under "#12 Alabama" would be a
 * caption about a game nobody played. Frozen on the fixture, "#4 Alabama at
 * Wisconsin" stays true for ever, which is what every scoreboard in the sport
 * does and the reason ESPN sends the rank with the fixture rather than with
 * the club.
 *
 * 🚨 0 means UNRANKED, and 99 never reaches this table. That is the feed's
 * sentinel for "outside the poll", and a column that stored it would put a
 * "#99" on two thirds of every board the first time somebody forgot. It is
 * normalised at the edge, in EspnProvider::rank(), which is the only place that
 * fact has to be known.
 *
 * 🚨 Everything here arrives in a payload the score sync ALREADY fetches. Not
 * one of these columns costs an outbound request — which is the reason they are
 * on the fixture rather than being asked for when a thread wants them.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) use ($schema) {
            foreach (['home_rank', 'away_rank'] as $column) {
                if (! $schema->hasColumn('picks_events', $column)) {
                    // 0 = unranked. A poll is 25 deep; a tiny int is generous.
                    $table->unsignedTinyInteger($column)->default(0);
                }
            }

            foreach (['home_record', 'away_record'] as $column) {
                if (! $schema->hasColumn('picks_events', $column)) {
                    // "2-0", "7-4-1". The feed's own text, printed as sent.
                    $table->string($column, 16)->default('');
                }
            }

            if (! $schema->hasColumn('picks_events', 'venue')) {
                $table->string('venue', 120)->default('');
            }

            if (! $schema->hasColumn('picks_events', 'venue_city')) {
                // "College Station, TX" — composed at the edge, because the
                // feed sends city, state and country separately and only some
                // of them, and a comma with nothing after it reads as a fault.
                $table->string('venue_city', 80)->default('');
            }

            if (! $schema->hasColumn('picks_events', 'broadcast')) {
                // "ABC", "ESPN2". The national listing where there is one.
                $table->string('broadcast', 60)->default('');
            }
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        foreach (['home_rank', 'away_rank', 'home_record', 'away_record', 'venue', 'venue_city', 'broadcast'] as $column) {
            if ($schema->hasColumn('picks_events', $column)) {
                $schema->table('picks_events', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    },
];
