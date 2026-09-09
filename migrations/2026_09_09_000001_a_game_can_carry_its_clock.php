<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * What a game looks like WHILE it is being played.
 *
 * A fixture already carries a kickoff, a status and a score, which is
 * everything a pick'em needs. A scoreboard needs the rest of it: which quarter,
 * how long is left, who has the ball, what down it is.
 *
 * 🚨 `clock_at` is the point of this table, not decoration. A period is safe to
 * print an hour later — a quarter lasts fifteen minutes — and a game clock is
 * a lie within seconds of being fetched. Carrying WHEN each was true is what
 * lets the thing that draws them decide which is still worth showing, rather
 * than every reader having to trust that the last sync was a moment ago.
 *
 * 🚨 Kept on the EVENT rather than in a table of its own. This is one row per
 * game that is already being read on every board that shows a fixture; a
 * separate table would be a join added to every one of those reads to hold
 * six columns that live and die with the row they describe.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('picks_events', 'period')) {
                $table->unsignedTinyInteger('period')->default(0);
            }

            if (! $schema->hasColumn('picks_events', 'clock')) {
                // "12:41". Short, and never parsed — it is printed as sent.
                $table->string('clock', 16)->default('');
            }

            if (! $schema->hasColumn('picks_events', 'clock_detail')) {
                // ESPN's own wording: "2nd Quarter", "Halftime", "End of 3rd".
                $table->string('clock_detail', 64)->default('');
            }

            if (! $schema->hasColumn('picks_events', 'clock_at')) {
                $table->unsignedInteger('clock_at')->default(0);
            }

            if (! $schema->hasColumn('picks_events', 'possession')) {
                // 'home', 'away' or '' — never a team id. See EspnProvider.
                $table->string('possession', 4)->default('');
            }

            if (! $schema->hasColumn('picks_events', 'down_distance')) {
                $table->string('down_distance', 32)->default('');
            }

            if (! $schema->hasColumn('picks_events', 'red_zone')) {
                $table->boolean('red_zone')->default(false);
            }
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        foreach (['period', 'clock', 'clock_detail', 'clock_at', 'possession', 'down_distance', 'red_zone'] as $column) {
            if ($schema->hasColumn('picks_events', $column)) {
                $schema->table('picks_events', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    },
];
