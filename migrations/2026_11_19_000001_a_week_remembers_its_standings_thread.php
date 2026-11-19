<?php

use Flarum\Database\Migration;

/**
 * The discussion the week's standings were published in.
 *
 * 🚨 Remembered by id, not found again by title. "Week 1 pick'em standings"
 * names a week in EVERY season, and any member can start a thread with that
 * title; looking it up by title alone had the Tuesday run edit last season's
 * post, or somebody else's.
 */
return Migration::addColumns('picks_weeks', [
    'standings_discussion_id' => ['integer', 'unsigned' => true, 'nullable' => true],
]);
