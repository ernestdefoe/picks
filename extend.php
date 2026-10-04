<?php

namespace Resofire\Picks;

use Flarum\Api\Resource;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Resofire\Picks\Service\Leagues\Leagues;
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
use Resofire\Picks\Api\Controller\SyncEspnController;
use Resofire\Picks\Api\Controller\SyncScoresController;
use Resofire\Picks\Api\Controller\SyncScoresStatusController;
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
use Resofire\Picks\Console\BackfillLeadInCommand;
use Resofire\Picks\Console\PollLiveScoresCommand;
use Resofire\Picks\Console\SyncBoxScoresCommand;
use Resofire\Picks\Console\SyncEspnCommand;
use Resofire\Picks\Console\PostStandingsCommand;
use Resofire\Picks\Console\SyncTeamsCommand;
use Resofire\Picks\Frontend\PicksPageContent;
use Resofire\Picks\PicksServiceProvider;

$extenders = [
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
        ->route('/picks', 'picks', PicksPageContent::class)
        ->route('/picks/week/{weekId}', 'picks.week', PicksPageContent::class)
        ->route('/u/{username}/picks-history', 'user.picks-history'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/less/admin.less')
        /*
         * 🚨 The league list reaches the admin from the registry rather than
         * being written into the JavaScript. A second copy in the bundle is a
         * copy that goes stale the first time an extension registers a
         * competition — which is the whole reason the registry exists.
         *
         * 🚨 Each entry carries its PROVIDER too, because the admin has to say
         * which button syncs a season. CollegeFootballData answers a year and a
         * week; ESPN answers a scoreboard. Offering the wrong one is a button
         * that runs and reports nothing changed.
         */
        ->content(function (Document $document): void {
            $document->payload['picksLeagues'] = (new Leagues())->manifest();
        }),

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
        ->default('ernestdefoe-picks.scores_sync_status', null)
        ->default('ernestdefoe-picks.scores_sync_result', null)
        ->default('ernestdefoe-picks.scores_sync_started', null)
        ->default('ernestdefoe-picks.espn_polling_enabled', false)
        ->default('ernestdefoe-picks.espn_poll_interval_minutes', 5)
        ->default('ernestdefoe-picks.nav_label', 'Picks')
        ->serializeToForum('picksNavLabel', 'ernestdefoe-picks.nav_label')
        // 🚨 Saved in the admin since the fork and read by nothing: the board
        // ignored "Always show Week 1" and "Current week (auto-detect)" alike.
        ->serializeToForum('picksDefaultWeekView', 'ernestdefoe-picks.default_week_view'),

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
        ->post('/picks/sync/espn',          'picks.sync.espn',            SyncEspnController::class)
        ->get('/picks/sync/scores/status',  'picks.sync.scores.status',   SyncScoresStatusController::class)
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
        ->command(SyncBoxScoresCommand::class)
        ->command(SyncEspnCommand::class)
        ->command(BackfillLeadInCommand::class)
        ->command(PostStandingsCommand::class)
        /*
         * Every minute, not every five.
         *
         * Five was chosen for SCORES, where a final arriving late is harmless.
         * The same command now carries the game CLOCK, and a clock five minutes
         * stale is wrong more often than it is right — a live thread showed
         * "1st 10:42" while the game was most of a drive further on.
         *
         * One minute is the floor available here anyway: schedule:run is driven
         * by a minutely timer, so nothing scheduled can be fresher than that.
         *
         * The cost is one request per minute — a single call covers every game
         * on the board (86 on a Saturday), so this does not scale with the
         * fixture list. See the read path for how the panel gets fresher than
         * a minute without adding any outbound calls per viewer.
         */
        ->schedule(PollLiveScoresCommand::class, function ($event) {
            $event->everyMinute()->withoutOverlapping();
        })
        /*
         * 🚨 Hourly, and cheap by construction: it fetches a WEEK at a time —
         * two calls cover every game on a Saturday — and returns immediately
         * once every finished game has one, which is most of the week.
         */
        ->schedule(SyncBoxScoresCommand::class, function ($event) {
            $event->hourly();
        })
        /*
         * 🚨 Every fifteen minutes, not every five. ESPN's scoreboard is ONE
         * call per league per run — far cheaper than the college sync — but a
         * board following six leagues at five-minute intervals is seventy-two
         * outbound calls an hour for fixtures that move a few times a day.
         * Live scores are `picks:poll-live-scores`' job; this is the schedule.
         */
        ->schedule(SyncEspnCommand::class, function ($event) {
            $event->everyFifteenMinutes()->withoutOverlapping();
        })
        /*
         * 🚨 The ranks and records on a fixture, kept current.
         *
         * Nothing was refreshing these. College football syncs its schedule
         * from CFBD, and that path writes no rank and no record at all — the
         * values on the board came from a single hand-run backfill and then
         * stood still. Measured on fbsfb: every unplayed fixture still carried
         * the records of 12 September, so on 1 October the board showed Pitt
         * 1-0 and Virginia Tech 2-0 when both were 4-0.
         *
         * 🚨 `--upcoming` only. A finished game keeps the lead-in it was played
         * under: the rank and record ESPN reports for a past fixture are the
         * ones each side carried INTO it, and rewriting those later with
         * today's poll would restate every result on the board.
         *
         * Daily rather than weekly, though the polls themselves move weekly —
         * records change every Saturday, and a board a week behind on those is
         * wrong far more often than it is right.
         *
         * The cost is one request per week that still has unplayed fixtures —
         * about a dozen a day early in a season, falling to one or two by the
         * end — against a feed that answers a whole week at a time. The
         * command's own ceiling of forty requests a run bounds it whatever
         * happens.
         */
        /*
         * 🚨 Tuesday morning, not Sunday night. The week's last games finish
         * late on Saturday and a handful of fixtures run into Monday; posting
         * before they are scored puts a table on the board that changes
         * underneath the people replying to it.
         */
        ->schedule(PostStandingsCommand::class, function ($event) {
            $event->weeklyOn(2, '09:00')->withoutOverlapping();
        })
        ->schedule(BackfillLeadInCommand::class, function ($event) {
            // Every six hours, not daily: it also carries kickoff times, and a
            // time announced in the afternoon has to land before Game Day opens
            // that game's thread. Four runs a day is still a few dozen requests.
            $event->cron('30 */6 * * *')->withoutOverlapping();
        /*
         * 🚨 Passed positionally, NOT as ['--upcoming' => true].
         *
         * A keyed entry renders as `--upcoming='1'`, and a no-value option
         * refuses that: "The --upcoming option does not accept a value." The
         * scheduler sends its output to /dev/null, so the task would have
         * failed silently every night and the board would have gone on showing
         * September's records with nothing anywhere saying why.
         */
        }, ['--upcoming']),
];

/*
 * The pick'em standings as a Page Builder block — only where Page Builder is
 * installed.
 *
 * 🚨 Guarded on the EXTENDER's class, not on the extension being enabled. This
 * file is read at boot, before anything knows which extensions are on, and
 * naming a class from an extension that is not installed is a fatal at compile
 * time rather than a missing block. The block class itself is never mentioned
 * outside this branch for the same reason: it extends a Page Builder base class
 * that would not be there to extend.
 */
if (class_exists(\Ernestdefoe\PageBuilder\Extend\PageBuilderBlock::class)) {
    $extenders[] = new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
        \Resofire\Picks\Block\LeaderboardBlock::class
    );
}

return $extenders;

