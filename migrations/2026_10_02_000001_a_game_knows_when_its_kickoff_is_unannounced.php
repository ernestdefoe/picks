<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Whether a fixture's kickoff TIME has been announced yet.
 *
 * 🚨 A college game's date is known months ahead and its time often only six
 * to twelve days out, when the networks pick their windows. Until then every
 * feed still sends a full timestamp — ESPN's is midnight Eastern on game day,
 * 04:00Z — and a board that cannot tell that placeholder from a real kickoff
 * prints it. On fbsfb that read "Auburn at Tennessee, tonight 11pm" for a game
 * kicking off at 2:30 the next afternoon, on the scoreboard and in every game
 * thread opened from it.
 *
 * The flag is what lets a page say "time TBA" instead of a confident lie.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events') || $schema->hasColumn('picks_events', 'time_tbd')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) {
            $table->boolean('time_tbd')->default(false);
        });
    },

    'down' => function (Builder $schema) {
        if ($schema->hasTable('picks_events') && $schema->hasColumn('picks_events', 'time_tbd')) {
            $schema->table('picks_events', function (Blueprint $table) {
                $table->dropColumn('time_tbd');
            });
        }
    },
];
