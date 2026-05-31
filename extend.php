<?php

namespace Resofire\Picks;

use Flarum\Api\Resource;
use Flarum\Extend;
use Resofire\Picks\Api\Controller\WeekOpenController;
use Resofire\Picks\Api\Controller\DeletePickController;
use Resofire\Picks\Api\Controller\EnterResultController;
use Resofire\Picks\Api\Controller\ListEventsController;
use Resofire\Picks\Api\Controller\ListLeaderboardController;
use Resofire\Picks\Api\Controller\ListPicksController;
use Resofire\Picks\Api\Controller\RefreshTeamLogoController;
use Resofire\Picks\Api\Controller\ResetDataController;
use Resofire\Picks\Api\Controller\SyncLogosController;
use Resofire\Picks\Api\Controller\SyncScheduleController;
use Resofire\Picks\Api\Controller\SyncScoresController;
use Resofire\Picks\Api\Controller\SyncTeamsController;
use Resofire\Picks\Api\Controller\PublicStatsController;
use Resofire\Picks\Api\Controller\StatsController;
use Resofire\Picks\Api\Controller\SubmitPickController;
use Resofire\Picks\Api\Controller\UserScoresController;
use Resofire\Picks\Api\Controller\UserHistoryController;
use Resofire\Picks\Api\Controller\LeaderboardContextController;
use Resofire\Picks\Api\Controller\LeaderboardHistoryController;
use Resofire\Picks\Api\Controller\SeedTestDataController;
use Resofire\Picks\Api\ForumPicksAttributes;
use Resofire\Picks\Api\Resource\EventResource;
use Resofire\Picks\Api\Resource\SeasonResource;
use Resofire\Picks\Api\Resource\TeamResource;
use Resofire\Picks\Api\Resource\WeekResource;
use Resofire\Picks\Console\PollLiveScoresCommand;
use Resofire\Picks\Console\SyncTeamsCommand;
use Resofire\Picks\PicksServiceProvider;

return [
    // -------------------------------------------------------------------------
    // Service provider — binds services with explicit dependencies
    // -------------------------------------------------------------------------
    (new Extend\ServiceProvider())
        ->register(PicksServiceProvider::class),

    // -------------------------------------------------------------------------
    // Frontend assets
    // -------------------------------------------------------------------------
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/less/forum.less')
        ->route('/picks', 'picks')
        ->route('/picks/week/{weekId}', 'picks.week')
        ->route('/u/{username}/picks-history', 'user.picks-history'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less'),

    new Extend\Locales(__DIR__.'/resources/locale'),

    // -------------------------------------------------------------------------
    // Serialize permission flags to forum JS
    // -------------------------------------------------------------------------
    (new Extend\ApiResource(Resource\ForumResource::class))
        ->fields(ForumPicksAttributes::class),

    // -------------------------------------------------------------------------
    // Settings defaults
    // -------------------------------------------------------------------------
    (new Extend\Settings())
        ->default('ernestdefoe-picks.cfbd_api_key', '')
        ->default('ernestdefoe-picks.season_year', (int) date('Y'))
        ->default('ernestdefoe-picks.conference_filter', '')
        ->default('ernestdefoe-picks.sync_regular_season', true)
        ->default('ernestdefoe-picks.sync_postseason', true)
        ->default('ernestdefoe-picks.auto_sync_enabled', false)
        ->default('ernestdefoe-picks.reverse_display', false)
        ->default('ernestdefoe-picks.picks_lock_offset_minutes', 0)
        ->default('ernestdefoe-picks.confidence_mode', false)
        ->default('ernestdefoe-picks.confidence_penalty', 'none')
        ->default('ernestdefoe-picks.auto_unlock_weeks', false)
        ->default('ernestdefoe-picks.default_week_view', 'current')
        ->default('ernestdefoe-picks.last_teams_sync', null)
        ->default('ernestdefoe-picks.last_schedule_sync', null)
        ->default('ernestdefoe-picks.last_scores_sync', null)
        ->default('ernestdefoe-picks.espn_polling_enabled', false)
        ->default('ernestdefoe-picks.espn_poll_interval_minutes', 5)
        ->default('ernestdefoe-picks.nav_label', 'Picks')
        ->serializeToForum('picksNavLabel', 'ernestdefoe-picks.nav_label'),

    // -------------------------------------------------------------------------
    // Permissions
    // -------------------------------------------------------------------------
    (new Extend\Policy())
        ->globalPolicy(Access\PicksPolicy::class),

    // -------------------------------------------------------------------------
    // API Resources
    // -------------------------------------------------------------------------
    new Extend\ApiResource(TeamResource::class),
    new Extend\ApiResource(SeasonResource::class),
    new Extend\ApiResource(WeekResource::class),
    new Extend\ApiResource(EventResource::class),

    // -------------------------------------------------------------------------
    // Custom API routes (non-resource actions)
    // -------------------------------------------------------------------------
    (new Extend\Routes('api'))
        ->get('/picks/events',              'picks.events.index',         ListEventsController::class)
        ->get('/picks/my-picks',            'picks.my-picks',             ListPicksController::class)
        ->get('/picks/leaderboard',         'picks.leaderboard',          ListLeaderboardController::class)
        ->post('/picks/submit',             'picks.submit',               SubmitPickController::class)
        ->delete('/picks/events/{id}/pick', 'picks.pick.delete',          DeletePickController::class)
        ->post('/picks/weeks/{id}/open',    'picks.weeks.open',           WeekOpenController::class)
        ->post('/picks/sync/teams',         'picks.sync.teams',           SyncTeamsController::class)
        ->post('/picks/sync/logos',         'picks.sync.logos',           SyncLogosController::class)
        ->post('/picks/sync/schedule',      'picks.sync.schedule',        SyncScheduleController::class)
        ->post('/picks/sync/scores',        'picks.sync.scores',          SyncScoresController::class)
        ->get('/picks/stats',               'picks.stats',                StatsController::class)
        ->get('/picks/public-stats',        'picks.public-stats',         PublicStatsController::class)
        ->post('/picks/reset',              'picks.reset',                ResetDataController::class)
        ->post('/picks/events/{id}/result', 'picks.events.result',        EnterResultController::class)
        ->post('/picks/teams/{id}/refresh-logo', 'picks.teams.refresh-logo', RefreshTeamLogoController::class)
        // ── New routes ────────────────────────────────────────────────────────
        ->get('/picks/user-scores',         'picks.user-scores',         UserScoresController::class)
        ->get('/picks/user-history',        'picks.user-history',        UserHistoryController::class)
        ->get('/picks/leaderboard-history', 'picks.leaderboard-history', LeaderboardHistoryController::class)
        ->get('/picks/leaderboard-context', 'picks.leaderboard-context', LeaderboardContextController::class)

        // Admin "Testing" tab: seed/clean test data (seed2026, seedFake2025,
        // cleanFake, wipeAll). The controller existed but was never routed.
        ->post('/picks/seed-test-data',     'picks.seed-test-data',      SeedTestDataController::class),

    // -------------------------------------------------------------------------
    // Console commands
    // -------------------------------------------------------------------------
    (new Extend\Console())
        ->command(SyncTeamsCommand::class)
        ->command(PollLiveScoresCommand::class)
        ->schedule(PollLiveScoresCommand::class, function ($event) {
            $event->everyFiveMinutes();
        }),
];
