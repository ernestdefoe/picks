<?php

namespace Resofire\Picks\Console;

use Illuminate\Console\Command;
use Resofire\Picks\Service\TeamSyncService;

class SyncTeamsCommand extends Command
{
    protected $signature = 'picks:sync-teams';
    protected $description = 'Sync FBS teams from the College Football Data API.';

    public function handle(TeamSyncService $teamSyncService): int
    {
        $this->info('Syncing FBS teams from CFBD...');

        try {
            $result = $teamSyncService->sync();
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Created : {$result['created']}");
        $this->info("Updated : {$result['updated']}");
        $this->info("Logos   : {$result['logos']}");

        if (! empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                $this->warn($error);
            }
        }

        $this->info('Team sync complete.');

        return self::SUCCESS;
    }
}
