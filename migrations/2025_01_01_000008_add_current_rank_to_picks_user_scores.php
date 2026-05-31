<?php

use Flarum\Database\Migration;

/*
 * Adds current_rank alongside the existing previous_rank so the leaderboard
 * movement arrows track correctly. previous_rank used to freeze at the
 * first-ever rank; storing the prior pass's rank in current_rank lets each
 * scoring pass roll the old current_rank into previous_rank.
 */
return Migration::addColumns('picks_user_scores', [
    'current_rank' => ['integer', 'unsigned' => true, 'nullable' => true, 'after' => 'previous_rank'],
]);
