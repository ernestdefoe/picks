<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('picks_weeks')) {
            return;
        }
        $schema->create('picks_weeks', function (Blueprint $table) {
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
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('picks_weeks');
    },
];
