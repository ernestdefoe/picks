<?php

use Flarum\Database\Migration;

return Migration::addColumns('picks_weeks', [
    'is_open' => ['boolean', 'default' => false, 'after' => 'end_date'],
]);
