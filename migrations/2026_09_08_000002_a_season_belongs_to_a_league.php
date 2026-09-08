<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * 🚨 The league belongs to the SEASON, not to a setting.
 *
 * A sport was briefly a single global setting, which is right for a board that
 * follows one league and wrong for a general sports forum — and wrong in the
 * worst way, because the moment a second league is added every existing game
 * silently changes sport. On the season it costs nothing: a board with one
 * league has one value in one column, and a board with five has five seasons
 * that each know what they are.
 *
 * 🚨 Defaults to college football on purpose. Every row that exists when this
 * runs was synced from CollegeFootballData, so the default is not a guess — it
 * is the only thing those rows can be.
 */
return Migration::addColumns('picks_seasons', [
    'league' => ['string', 'length' => 20, 'default' => 'cfb'],
]);
