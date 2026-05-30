<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('picks_events')) {
            return;
        }
        $schema->create('picks_events', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('week_id')->nullable();
            $table->unsignedInteger('home_team_id');
            $table->unsignedInteger('away_team_id');
            $table->unsignedInteger('cfbd_id')->nullable()->unique();
            $table->boolean('neutral_site')->default(false);
            $table->dateTime('match_date');
            $table->dateTime('cutoff_date');
            $table->string('status', 20)->default('scheduled');
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->string('result', 10)->nullable();
            $table->timestamps();

            $table->foreign('week_id')
                ->references('id')
                ->on('picks_weeks')
                ->onDelete('set null');

            $table->foreign('home_team_id')
                ->references('id')
                ->on('picks_teams')
                ->onDelete('restrict');

            $table->foreign('away_team_id')
                ->references('id')
                ->on('picks_teams')
                ->onDelete('restrict');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('picks_events');
    },
];
