<?php

use Flarum\Group\Group;
use Illuminate\Database\Schema\Builder;

/*
 * Members can see Picks and make picks out of the box. Managing the game stays
 * admin-only until it is granted in the Permissions grid, and guests see Picks
 * only once a forum ticks the box for them.
 *
 * 🚨 Dated BEFORE every other Picks migration on purpose. Migrations run in
 * filename order, so on a fresh install this one runs first and finds no Picks
 * migration recorded yet. On a forum that already runs Picks the others are
 * recorded, and it grants nothing: there an empty permission is a choice the
 * admin made (or a grant they removed), not a missing default.
 *
 * Each grant is also skipped if any group already holds the permission, and
 * the grants made are recorded so rolling back removes those and nothing else.
 */

$seededKey = 'ernestdefoe-picks.seeded_permissions';

return [
    'up' => function (Builder $schema) use ($seededKey) {
        $db = $schema->getConnection();

        if ($db->table('migrations')->where('extension', 'ernestdefoe-picks')->exists()) {
            return;
        }

        $seeded = [];

        foreach (['picks.view', 'picks.makePicks'] as $permission) {
            if ($db->table('group_permission')->where('permission', $permission)->exists()
                || $db->table('groups')->where('id', Group::MEMBER_ID)->doesntExist()) {
                continue;
            }

            $db->table('group_permission')->insert(['group_id' => Group::MEMBER_ID, 'permission' => $permission]);
            $seeded[] = $permission;
        }

        if ($seeded) {
            $db->table('settings')->updateOrInsert(['key' => $seededKey], ['value' => json_encode($seeded)]);
        }
    },

    'down' => function (Builder $schema) use ($seededKey) {
        $db = $schema->getConnection();

        $seeded = json_decode((string) $db->table('settings')->where('key', $seededKey)->value('value'), true) ?: [];

        if ($seeded) {
            $db->table('group_permission')->where('group_id', Group::MEMBER_ID)->whereIn('permission', $seeded)->delete();
        }

        $db->table('settings')->where('key', $seededKey)->delete();
    },
];
