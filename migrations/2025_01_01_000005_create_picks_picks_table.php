<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if ($schema->hasTable('picks_picks')) {
            return;
        }
        $schema->create('picks_picks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('event_id');
            $table->string('selected_outcome', 10);
            $table->boolean('is_correct')->nullable();
            $table->unsignedSmallInteger('confidence')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'event_id']);

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');

            $table->foreign('event_id')
                ->references('id')
                ->on('picks_events')
                ->onDelete('cascade');
        });
    },
    'down' => function (Builder $schema) {
        $schema->dropIfExists('picks_picks');
    },
];
