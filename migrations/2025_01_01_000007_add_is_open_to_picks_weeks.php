<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

return [
    'up' => function (Builder $schema) {
        if (! $schema->hasTable('picks_weeks') || $schema->hasColumn('picks_weeks', 'is_open')) {
            return;
        }
        $schema->table('picks_weeks', function (Blueprint $table) {
            $table->boolean('is_open')->default(false)->after('end_date');
        });
    },
    'down' => function (Builder $schema) {
        if (! $schema->hasColumn('picks_weeks', 'is_open')) {
            return;
        }
        $schema->table('picks_weeks', function (Blueprint $table) {
            $table->dropColumn('is_open');
        });
    },
];
