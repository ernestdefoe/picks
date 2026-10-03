<?php

use Carbon\Carbon;
use Illuminate\Database\Schema\Builder;

/**
 * Reopens games that were locked by an unannounced kickoff's placeholder.
 *
 * 🚨 A fixture synced before its time was announced carried a lock of noon UTC
 * on game day. Any save after that marked it `closed`, and when the real
 * kickoff arrived the lock moved but the status never did — so every afternoon
 * and evening game whose time came in late stayed locked from the morning. The
 * code no longer does this, but a fix in the code cannot reach rows already
 * written: a game in this state today is still closed until something saves it.
 *
 * A closed game with no result whose lock is still ahead has not kicked off, so
 * reopening it is always right.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        $schema->getConnection()->table('picks_events')
            ->where('status', 'closed')
            ->whereNull('result')
            ->where('cutoff_date', '>', Carbon::now()->utc()->format('Y-m-d H:i:s'))
            ->update(['status' => 'scheduled']);
    },

    'down' => function (Builder $schema) {
        // Nothing to undo: the auto-close puts any of these back once its lock passes.
    },
];
