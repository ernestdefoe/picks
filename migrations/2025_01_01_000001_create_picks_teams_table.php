<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists(
    'picks_teams',
    function (Blueprint $table) {
        $table->increments('id');
        $table->string('name', 100);
        $table->string('slug', 100)->unique();
        $table->string('abbreviation', 10)->nullable();
        $table->string('conference', 100)->nullable();
        $table->unsignedInteger('cfbd_id')->nullable()->unique();
        $table->unsignedInteger('espn_id')->nullable()->unique();
        $table->string('logo_path', 255)->nullable();
        $table->string('logo_dark_path', 255)->nullable();
        $table->boolean('logo_custom')->default(false);
        $table->timestamps();
    }
);
