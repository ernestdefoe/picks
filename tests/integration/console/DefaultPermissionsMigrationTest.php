<?php

namespace Resofire\Picks\Tests\integration\console;

use Flarum\Group\Group;
use Flarum\Testing\integration\ConsoleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A fresh install lets members see Picks and make picks; a forum that already
 * runs Picks, or already granted a permission, keeps what it has; rolling back
 * removes only what the install granted.
 *
 * Each test ends migrated, as it started: MySQL commits schema changes
 * whatever transaction the test runs in.
 */
class DefaultPermissionsMigrationTest extends ConsoleTestCase
{
    private const MIGRATION = '2025_01_01_000000_members_can_use_picks_on_a_fresh_install';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
    }

    private function grants(): array
    {
        return $this->database()->table('group_permission')->where('permission', 'like', 'picks.%')
            ->orderBy('permission')->orderBy('group_id')->get()
            ->map(fn ($r) => [(int) $r->group_id, $r->permission])->all();
    }

    private function migrate(bool $reset = true): void
    {
        if ($reset) {
            $this->runCommand(['command' => 'migrate:reset', '--extension' => 'ernestdefoe-picks']);
        }

        $this->runCommand(['command' => 'migrate']);
    }

    #[Test]
    public function a_fresh_install_lets_members_view_and_make_picks()
    {
        $this->assertSame([
            [Group::MEMBER_ID, 'picks.makePicks'],
            [Group::MEMBER_ID, 'picks.view'],
        ], $this->grants(), 'Members only: no guests, nothing for manage or history');
    }

    #[Test]
    public function rolling_back_removes_only_what_the_install_granted()
    {
        $this->database()->table('group_permission')->insert(['group_id' => Group::MODERATOR_ID, 'permission' => 'picks.manage']);

        $this->runCommand(['command' => 'migrate:reset', '--extension' => 'ernestdefoe-picks']);

        $this->assertSame([[Group::MODERATOR_ID, 'picks.manage']], $this->grants());
        $this->assertNull($this->database()->table('settings')->where('key', 'ernestdefoe-picks.seeded_permissions')->value('value'));

        // migrate:reset runs DDL, which commits the test transaction on MySQL,
        // so leave nothing behind for the next test.
        $this->database()->table('group_permission')->where('permission', 'like', 'picks.%')->delete();
        $this->migrate(false);
    }

    #[Test]
    public function a_permission_another_group_holds_is_left_alone()
    {
        $this->runCommand(['command' => 'migrate:reset', '--extension' => 'ernestdefoe-picks']);
        $this->database()->table('group_permission')->where('permission', 'like', 'picks.%')->delete();
        $this->database()->table('group_permission')->insert(['group_id' => Group::MODERATOR_ID, 'permission' => 'picks.view']);

        $this->migrate(false);

        $this->assertSame([
            [Group::MEMBER_ID, 'picks.makePicks'],
            [Group::MODERATOR_ID, 'picks.view'],
        ], $this->grants());

        // Rolling back keeps the moderators' grant, which the install did not make.
        $this->runCommand(['command' => 'migrate:reset', '--extension' => 'ernestdefoe-picks']);
        $this->assertSame([[Group::MODERATOR_ID, 'picks.view']], $this->grants());

        $this->database()->table('group_permission')->where('permission', 'like', 'picks.%')->delete();
        $this->migrate(false);
    }

    #[Test]
    public function a_forum_already_running_picks_keeps_its_permissions()
    {
        // An update, not an install: the other migrations ran long ago, and
        // the admin took every grant away.
        $db = $this->database();
        $db->table('group_permission')->where('permission', 'like', 'picks.%')->delete();
        $db->table('settings')->where('key', 'ernestdefoe-picks.seeded_permissions')->delete();
        $db->table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->migrate(false);

        $this->assertSame([], $this->grants());
        $this->assertTrue($db->table('migrations')->where('migration', self::MIGRATION)->exists(), 'Recorded as run');

        $this->migrate();
    }
}
