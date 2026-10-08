<?php

namespace Resofire\Picks\Tests\integration;

use Carbon\Carbon;
use Flarum\Group\Group;
use Flarum\User\User;

/**
 * One season, an open week and a locked one, four teams and three games:
 * game 1 open for picks, game 2 past its cutoff, game 3 in the locked week.
 * Members (group 3) may view and pick; nobody but admins manages.
 * Users: 1 admin, 2 member, 3 another member.
 */
trait SeedsPicks
{
    protected function seedPicks(): void
    {
        $soon = Carbon::now()->addDay();
        $past = Carbon::now()->subDay();

        $this->prepareDatabase([
            User::class => [
                $this->normalUser(),
                ['id' => 3, 'username' => 'rival', 'email' => 'rival@machine.local', 'is_email_confirmed' => 1],
            ],
            'group_user' => [['user_id' => 3, 'group_id' => Group::MEMBER_ID]],
            'group_permission' => [
                ['group_id' => Group::MEMBER_ID, 'permission' => 'picks.view'],
                ['group_id' => Group::MEMBER_ID, 'permission' => 'picks.makePicks'],
            ],
            'picks_seasons' => [
                ['id' => 1, 'name' => '2026', 'slug' => '2026', 'year' => 2026],
            ],
            'picks_weeks' => [
                ['id' => 1, 'season_id' => 1, 'name' => 'Week 1', 'week_number' => 1, 'is_open' => true],
                ['id' => 2, 'season_id' => 1, 'name' => 'Week 2', 'week_number' => 2, 'is_open' => false],
            ],
            'picks_teams' => [
                ['id' => 1, 'name' => 'Alabama', 'slug' => 'alabama', 'abbreviation' => 'ALA'],
                ['id' => 2, 'name' => 'Georgia', 'slug' => 'georgia', 'abbreviation' => 'UGA'],
                ['id' => 3, 'name' => 'Texas', 'slug' => 'texas', 'abbreviation' => 'TEX'],
                ['id' => 4, 'name' => 'Ohio State', 'slug' => 'ohio-state', 'abbreviation' => 'OSU'],
            ],
            'picks_events' => [
                ['id' => 1, 'week_id' => 1, 'home_team_id' => 1, 'away_team_id' => 2, 'match_date' => $soon, 'cutoff_date' => $soon, 'status' => 'scheduled'],
                ['id' => 2, 'week_id' => 1, 'home_team_id' => 3, 'away_team_id' => 4, 'match_date' => $past, 'cutoff_date' => $past, 'status' => 'scheduled'],
                ['id' => 3, 'week_id' => 2, 'home_team_id' => 2, 'away_team_id' => 3, 'match_date' => $soon, 'cutoff_date' => $soon, 'status' => 'scheduled'],
            ],
        ]);
    }

    /** A request that gets past CSRF for a guest, and authenticates anyone else. */
    protected function call(string $method, string $path, ?int $actor = null, ?array $json = null, array $query = [])
    {
        $options = $json === null ? [] : ['json' => $json];

        if ($actor) {
            return $this->send($this->request($method, $path, $options + ['authenticatedAs' => $actor])->withQueryParams($query));
        }

        $request = $this->request($method, $path, $options)->withQueryParams($query);

        return $this->send($method === 'GET' ? $request : $this->requestWithCsrfToken($request));
    }

    protected function body($response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
