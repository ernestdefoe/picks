<?php

namespace Resofire\Picks\Tests\integration\console;

use Flarum\Testing\integration\ConsoleTestCase;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\Attributes\Test;

/**
 * The confidence-picks value index has a short, explicit name, because the
 * name Laravel generates is too long for MySQL behind a long table prefix.
 * Forums that ran the migration before keep the generated name, so rolling
 * back has to work with either.
 *
 * Each test ends migrated, as it started: MySQL commits schema changes
 * whatever transaction the test runs in.
 */
class ConfidencePicksMigrationTest extends ConsoleTestCase
{
    private const TABLE = 'picks_confidence_picks';

    private const NAME = 'picks_confidence_value_unique';

    protected function setUp(): void
    {
        parent::setUp();

        $this->extension('ernestdefoe-picks');
    }

    private function schema()
    {
        return $this->database()->getSchemaBuilder();
    }

    private function resetAndMigrate(): void
    {
        $this->runCommand(['command' => 'migrate:reset', '--extension' => 'ernestdefoe-picks']);
        $this->assertFalse($this->schema()->hasTable(self::TABLE), 'Rolled back');

        $this->runCommand(['command' => 'migrate']);
        $this->assertTrue($this->schema()->hasTable(self::TABLE), 'Migrated again');
    }

    #[Test]
    public function the_value_index_has_the_short_name()
    {
        $this->assertTrue($this->schema()->hasIndex(self::TABLE, self::NAME, 'unique'));
    }

    #[Test]
    public function a_new_install_rolls_back_and_migrates_again()
    {
        $this->resetAndMigrate();

        $this->assertTrue($this->schema()->hasIndex(self::TABLE, self::NAME, 'unique'));
    }

    #[Test]
    public function a_forum_with_the_old_generated_name_rolls_back_and_migrates_again()
    {
        $connection = $this->database();
        $old = $connection->getTablePrefix().self::TABLE.'_user_id_week_id_confidence_unique';
        $limit = $connection->getDriverName() === 'pgsql' ? 63 : 64;

        // The old name could only exist where it fitted; behind a prefix too
        // long for it the table was never created, and there is nothing to
        // simulate.
        if (strlen($old) <= $limit || $connection->getDriverName() === 'sqlite') {
            $this->schema()->table(self::TABLE, function (Blueprint $table) use ($old) {
                $table->dropUnique(self::NAME);
                $table->unique(['user_id', 'week_id', 'confidence'], $old);
            });
            $this->assertTrue($this->schema()->hasIndex(self::TABLE, $old, 'unique'));
        }

        $this->resetAndMigrate();

        $this->assertTrue($this->schema()->hasIndex(self::TABLE, self::NAME, 'unique'));
    }
}
