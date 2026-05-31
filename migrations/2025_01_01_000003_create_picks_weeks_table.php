<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists(
    'picks_weeks',
    function (Blueprint $table) {
        $table->increments('id');
        $table->unsignedInteger('season_id');
        $table->string('name', 100);
        $table->unsignedSmallInteger('week_number')->nullable();
        $table->string('season_type', 20)->default('regular');
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->timestamps();

        $table->foreign('season_id')
            ->references('id')
            ->on('picks_seasons')
            ->onDelete('cascade');
    }
);
