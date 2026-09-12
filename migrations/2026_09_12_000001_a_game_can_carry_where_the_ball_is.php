<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Where the ball is, alongside what down it is.
 *
 * `down_distance` deliberately holds the SHORT form — "2nd & 8" — because a
 * scoreboard strip is narrow and the yard line is the part that ages worst.
 * But "2nd & 8" without it does not tell you whether a drive is going
 * anywhere, and that is most of what a live board is read for.
 *
 * 🚨 Stored as the feed's own text ("BC 49"), not as a number. A yard line is
 * meaningless without the side of the field it is on, and the side is named
 * after a team whose abbreviation this table does not have to hand. ESPN
 * already composes the string correctly, including "50" with no side at all
 * and goal-line cases, so it is printed as sent rather than rebuilt here.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('picks_events', 'ball_on')) {
                // "BC 49", "OPP 3", "50". Short, and never parsed.
                $table->string('ball_on', 16)->default('');
            }
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) use ($schema) {
            if ($schema->hasColumn('picks_events', 'ball_on')) {
                $table->dropColumn('ball_on');
            }
        });
    },
];
