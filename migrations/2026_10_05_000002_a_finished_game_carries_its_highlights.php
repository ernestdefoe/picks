<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * The highlight clips ESPN publishes for a finished game.
 *
 * 🚨 Clip ids and captions, never markup. A thread draws them in its own
 * component and builds the player from the id alone, so nothing here is ever
 * pasted into a post — generated post text is PARSED, and an iframe written
 * into one is either escaped into noise or, worse, not.
 *
 * `highlights_checked_at` is what lets a pass come back for clips that are
 * published hours after the final whistle without asking ESPN on every run.
 *
 * Created through the schema builder, so the table prefix is applied.
 */
return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        $schema->table('picks_events', function (Blueprint $table) use ($schema) {
            if (! $schema->hasColumn('picks_events', 'highlights')) {
                $table->text('highlights')->nullable();
            }

            if (! $schema->hasColumn('picks_events', 'highlights_checked_at')) {
                $table->dateTime('highlights_checked_at')->nullable();
            }
        });
    },

    'down' => function (Builder $schema) {
        if (! $schema->hasTable('picks_events')) {
            return;
        }

        foreach (['highlights', 'highlights_checked_at'] as $column) {
            if ($schema->hasColumn('picks_events', $column)) {
                $schema->table('picks_events', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    },
];
