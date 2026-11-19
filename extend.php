<?php

namespace Resofire\Picks;

use Flarum\Api\Resource;
use Flarum\Extend;
use Flarum\Frontend\Document;
use Flarum\Locale\TranslatorInterface;
use Flarum\Search\Database\DatabaseSearchDriver;
use Resofire\Picks\Api\Controller\Confidence;
use Resofire\Picks\Api\Controller\EnterResultController;
use Resofire\Picks\Api\Controller\LeaderboardContextController;
use Resofire\Picks\Api\Controller\LeaderboardHistoryController;
use Resofire\Picks\Api\Controller\ListLeaderboardController;
use Resofire\Picks\Api\Controller\RefreshTeamLogoController;
use Resofire\Picks\Api\Controller\ResetDataController;
use Resofire\Picks\Api\Controller\SeedTestDataController;
use Resofire\Picks\Api\Controller\StatsController;
use Resofire\Picks\Api\Controller\StartSyncController;
use Resofire\Picks\Api\Controller\SyncEspnController;
use Resofire\Picks\Api\Controller\SyncStatusController;
use Resofire\Picks\Api\Controller\UserHistoryController;
use Resofire\Picks\Api\Controller\UserScoresController;
use Resofire\Picks\Api\ForumPicksAttributes;
use Resofire\Picks\Api\Resource\EventResource;
use Resofire\Picks\Api\Resource\PickResource;
use Resofire\Picks\Api\Resource\SeasonResource;
use Resofire\Picks\Api\Resource\TeamResource;
use Resofire\Picks\Api\Resource\WeekResource;
use Resofire\Picks\Console\BackfillLeadInCommand;
use Resofire\Picks\Console\PollLiveScoresCommand;
use Resofire\Picks\Console\PostStandingsCommand;
use Resofire\Picks\Console\SyncBoxScoresCommand;
use Resofire\Picks\Console\SyncEspnCommand;
use Resofire\Picks\Console\SyncTeamsCommand;
use Resofire\Picks\Console\UnlockWeeksCommand;
use Resofire\Picks\Frontend\PicksPageContent;
use Resofire\Picks\Listener;
use Resofire\Picks\Pick;
use Resofire\Picks\PickEvent;
use Resofire\Picks\Search;
use Resofire\Picks\Service\Leagues\Leagues;

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
        // The pick'em pages are their own chunks, loaded only when opened.
        ->jsDirectory(__DIR__.'/js/dist/forum')
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
            $document->payload['picksLeagues'] = resolve(Leagues::class)->manifest(resolve(TranslatorInterface::class));
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
        // The Confidence contest: off until an admin turns it on.
        ->default('ernestdefoe-picks.confidence10_enabled', false)
        ->default('ernestdefoe-picks.confidence10_games', 10)
        ->default('ernestdefoe-picks.confidence10_penalty', 'none')
        ->default('ernestdefoe-picks.auto_unlock_weeks', false)
        ->default('ernestdefoe-picks.default_week_view', 'current')
        ->default('ernestdefoe-picks.last_teams_sync', null)
        ->default('ernestdefoe-picks.last_schedule_sync', null)
        ->default('ernestdefoe-picks.last_scores_sync', null)
        ->default('ernestdefoe-picks.espn_polling_enabled', false)
        ->default('ernestdefoe-picks.espn_poll_interval_minutes', 5)
        ->default('ernestdefoe-picks.nav_label', '')
        // 'home_vs_away' is how every forum read a matchup before this setting
        // existed, so it stays the default and nobody's board changes on update.
        ->default('ernestdefoe-picks.matchup_format', 'home_vs_away')
        ->serializeToForum('picksNavLabel', 'ernestdefoe-picks.nav_label')
        ->serializeToForum('picksMatchupFormat', 'ernestdefoe-picks.matchup_format')
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
    new Extend\ApiResource(PickResource::class),

    // filter[week], filter[status] and team-name search on games; the actor's
    // own picks by week.
    (new Extend\SearchDriver(DatabaseSearchDriver::class))
        ->addSearcher(PickEvent::class, Search\EventSearcher::class)
        ->addFilter(Search\EventSearcher::class, Search\Filter\EventWeekFilter::class)
        ->addFilter(Search\EventSearcher::class, Search\Filter\EventStatusFilter::class)
        ->setFulltext(Search\EventSearcher::class, Search\EventFulltext::class)
        ->addSearcher(Pick::class, Search\PickSearcher::class)
        ->addFilter(Search\PickSearcher::class, Search\Filter\PickWeekFilter::class),

    // -------------------------------------------------------------------------
    // Custom API routes (non-resource actions)
    // -------------------------------------------------------------------------
    (new Extend\Routes('api'))
        ->get('/picks/leaderboard', 'picks.leaderboard', ListLeaderboardController::class)
        // The sync buttons queue a job and answer 202; the admin polls the status.
        ->post('/picks/sync/{task:teams|logos|schedule|scores}', 'picks.sync', StartSyncController::class)
        ->post('/picks/sync/espn', 'picks.sync.espn', SyncEspnController::class)
        ->get('/picks/sync/{task:[a-z0-9-]+}/status', 'picks.sync.status', SyncStatusController::class)
        ->get('/picks/stats', 'picks.stats', StatsController::class)
        ->post('/picks/reset', 'picks.reset', ResetDataController::class)
        ->post('/picks/events/{id}/result', 'picks.events.result', EnterResultController::class)
        ->post('/picks/teams/{id}/refresh-logo', 'picks.teams.refresh-logo', RefreshTeamLogoController::class)
        ->get('/picks/user-scores', 'picks.user-scores', UserScoresController::class)
        ->get('/picks/user-history', 'picks.user-history', UserHistoryController::class)
        ->get('/picks/leaderboard-history', 'picks.leaderboard-history', LeaderboardHistoryController::class)
        ->get('/picks/leaderboard-context', 'picks.leaderboard-context', LeaderboardContextController::class)

        // Admin "Testing" tab: seed/clean test data (seed2026, seedFake2025,
        // cleanFake, wipeAll). The controller existed but was never routed.
        ->post('/picks/seed-test-data', 'picks.seed-test-data', SeedTestDataController::class)

        // ── Confidence contest (Confidence\ConfidenceContest) ─────────────────
        ->get('/picks/confidence', 'picks.confidence', Confidence\BoardController::class)
        ->post('/picks/confidence', 'picks.confidence.save', Confidence\SaveController::class)
        ->get('/picks/confidence/leaderboard', 'picks.confidence.leaderboard', Confidence\LeaderboardController::class)
        ->get('/picks/confidence/history', 'picks.confidence.history', Confidence\HistoryController::class)
        ->get('/picks/confidence/user-history', 'picks.confidence.user-history', Confidence\UserHistoryController::class)
        ->get('/picks/confidence/weeks/{id}', 'picks.confidence.week', Confidence\ShowSelectionController::class)
        ->post('/picks/confidence/weeks/{id}', 'picks.confidence.week.save', Confidence\SaveSelectionController::class)
        ->post('/picks/confidence/weeks/{id}/auto', 'picks.confidence.week.auto', Confidence\AutoSelectionController::class),

    // Turning auto-unlock ON opens whatever is already due, there and then.
    (new Extend\Event())
        ->listen(\Flarum\Settings\Event\Saved::class, Listener\UnlockDueWeeksOnSettingsSaved::class),

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
        ->command(UnlockWeeksCommand::class)
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
         * 🚨 Its own command, not a line in the score poll: the poll returns
         * early when ESPN polling is off or nothing is on today, and a board
         * that scores by hand or from CFBD still wants its weeks to open.
         * Plain, with no options — see the 🚨 at the end of this list.
         */
        ->schedule(UnlockWeeksCommand::class, function ($event) {
            $event->everyFifteenMinutes()->withoutOverlapping();
        })
        /*
         * 🚨 Tuesday morning, not Sunday night. The week's last games finish
         * late on Saturday and a handful of fixtures run into Monday; posting
         * before they are scored puts a table on the board that changes
         * underneath the people replying to it.
         */
        ->schedule(PostStandingsCommand::class, function ($event) {
            $event->weeklyOn(2, '09:00')->withoutOverlapping();
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
         * Every six hours rather than weekly, though the polls themselves move
         * weekly — records change every Saturday, and a board a week behind on
         * those is wrong far more often than it is right. It also carries
         * kickoff times, and a time announced in the afternoon has to land
         * before Game Day opens that game's thread.
         *
         * The cost is one request per week that still has unplayed fixtures —
         * about a dozen a run early in a season, falling to one or two by the
         * end — against a feed that answers a whole week at a time. The
         * command's own ceiling of forty requests a run bounds it whatever
         * happens.
         */
        ->schedule(BackfillLeadInCommand::class, function ($event) {
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
 * The pick'em standings as a Page Builder block, only where Page Builder is
 * enabled.
 *
 * 🚨 The Page Builder classes are named only inside the callback, which runs
 * only when that extension is on. LeaderboardBlock extends a Page Builder base
 * class, so loading it without Page Builder installed would be a fatal.
 */
$extenders[] = (new Extend\Conditional())
    ->whenExtensionEnabled('ernestdefoe-page-builder', fn () => [
        new \Ernestdefoe\PageBuilder\Extend\PageBuilderBlock(
            \Resofire\Picks\Block\LeaderboardBlock::class
        ),
    ]);

/*
 * The weekly standings discussion goes into the configured tag only where
 * flarum/tags is enabled; without it the discussion is posted untagged.
 */
$extenders[] = (new Extend\Conditional())
    ->whenExtensionEnabled('flarum-tags', fn () => [
        (new Extend\ServiceProvider())->register(\Resofire\Picks\Standings\TagsServiceProvider::class),
    ]);

return $extenders;
