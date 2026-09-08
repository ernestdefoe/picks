<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * 🚨 `cfbd_id` cannot carry an ESPN id, and the reason is not the type.
 *
 * It would fit — ESPN's event ids are numeric and well inside an unsigned
 * integer, and CollegeFootballData in fact reuses ESPN's ids for college
 * football games. What it cannot carry is the AMBIGUITY: once two providers
 * write to one column, `cfbd_id = 401772936` means "the CFBD game" on one row
 * and "the ESPN event" on another, its unique index spans both, and nothing in
 * the schema says which is which. The first collision between a real CFBD id
 * and a real ESPN id from another sport would silently overwrite a game.
 *
 * So a provider's own id gets its own column, as a string, because not every
 * provider's id is a number. `cfbd_id` stays exactly as it is: every existing
 * row keeps working, and nothing has to be backfilled before the next sync.
 */
return Migration::addColumns('picks_events', [
    'external_id' => ['string', 'length' => 40, 'nullable' => true],
]);
