<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('picks_user_scores')) {
            return;
        }
        $schema->create('picks_user_scores', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('season_id')->nullable();
            $table->unsignedInteger('week_id')->nullable();
            $table->unsignedInteger('total_points')->default(0);
            $table->unsignedInteger('total_picks')->default(0);
            $table->unsignedInteger('correct_picks')->default(0);
            $table->decimal('accuracy', 5, 2)->default(0.00);
            $table->unsignedInteger('previous_rank')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'season_id', 'week_id']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('season_id')
                ->references('id')
                ->on('picks_seasons')
                ->onDelete('cascade');

            $table->foreign('week_id')
                ->references('id')
                ->on('picks_weeks')
                ->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('picks_user_scores');
    },
];
