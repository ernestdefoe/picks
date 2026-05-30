<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTableIfNotExists(
    'picks_seasons',
    function (Blueprint $table) {
        $table->increments('id');
        $table->string('name', 100);
        $table->string('slug', 100)->unique();
        $table->unsignedSmallInteger('year');
        $table->date('start_date')->nullable();
        $table->date('end_date')->nullable();
        $table->timestamps();
    }
);
