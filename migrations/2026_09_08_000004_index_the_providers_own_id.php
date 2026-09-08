<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * The index, separately from the column.
 *
 * 🚨 `Migration::addColumns` cannot declare one, and the sync looks every game
 * up by this id on every run — a full ESPN scoreboard is a few hundred rows a
 * minute against a table that grows all season. Without an index this is the
 * query that gets slow quietly, months later, on somebody else's forum.
 *
 * 🚨 NOT unique. Two leagues can and do share an event id space — ESPN numbers
 * its own events globally, but a provider added later need not — and a unique
 * index would reject the second league's game rather than the duplicate it was
 * meant to catch. Uniqueness is enforced where the pairing is known: by
 * league, in the sync.
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('picks_events', function (Blueprint $table) {
            $table->index('external_id', 'picks_events_external_id_index');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('picks_events', function (Blueprint $table) {
            $table->dropIndex('picks_events_external_id_index');
        });
    },
];
