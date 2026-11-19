# Picks — Floxum wave 2, paused 2026-11-19 (resume after the Nov 26 usage reset)

Goal: Floxum 100 on the next release (2.16.7 scored 89). Brief: ~/github/command-center/floxum/fix-wave-brief.md.
Branch `wip/floxum-wave` (local only). Based on the 2.16.7 tag, so rebase onto origin/main (3 CI-only commits) before release.
Nothing pushed, tagged or deployed.

## Done (committed on this branch, tests green on SQLite/MySQL 9.6/PostgreSQL 18 at e625784)
- Both 2.16.7 findings: constructor injection instead of resolve(); Page Builder via Extend\Conditional + optional-dependencies.
- Pre-floxum findings fixed: standings post picks this season's week and edits only its own thread (week remembers
  `standings_discussion_id`, migration 2026_11_19_000001), created via JSON:API, tags via Conditional(flarum-tags);
  /picks/user-scores gated by picks.viewHistory; Page Builder season table = current season; board week summed in SQL +
  cached (proved equal to old loop, BoardWeekTest); Leagues singleton; Confidence selection split in 3 handlers;
  logo CDN path college-only; scoring batched per scope (BatchScoringTest: same totals as old code, no N+1);
  admin syncs queued (AdminSync/AdminSyncJob + /picks/sync/{task}/status, admin runSync.ts polls);
  picks-picks JSON:API resource + picks-events searcher filters (week/status/q) replace my-picks/submit/delete/events/open;
  dead relations, empty admin initializer, stale comments, settings fallbacks, declare const m, TeamEditModal FormModal fix.
- Board + admin verified in headless Chrome on ~/Herd/gauntlet-shots (pick/change/withdraw, 390px, games search, week switch, sync poll).

## In the WIP commit (NOT yet tested with phpunit)
- Long-function refactor (Floxum: every function > 50 lines is flagged). Admin + forum TSX done (tsc + prettier clean).
  PHP partly done: EspnProvider split into Espn/BoxScoreParser + Espn/FixtureParser (byte-identical output on fixtures),
  plus SyncScoresService, BackfillLeadIn, UserScores, TestDataSeeder, Team/Schedule/EspnSync, ConfidenceContest, Profile,
  EventResource, UserHistory, LeaderboardBlock, LeaderboardContext. phpstan OK, tests/run.php 32/32.

## To do on resume
1. Run the integration suite (PG container picks_pg_test on :55651, or re-create) — the WIP refactor is untested there.
2. Split the PHP functions still over 50 lines (run `python3 ~/.claude/skills/pre-floxum/longfuncs.py .` — note it only
   prints the top 40): ListLeaderboardController::handle 78, BoxScoreService fetchWeek/performers/leaders/normalise,
   Confidence/UserHistoryController::handle, EspnProvider::broadcasts/parseHighlights, LeaderboardHistoryController::handle,
   ScorePicksJob handle/updateRankMovements, SyncScoresService applyEspnEvent/sync/writeLeadIn/applyCfbdGame, Rules::validate,
   StatsController::coverage, Team/SeasonResource::fields, CurrentSeasonService::pickBoardWeek, ForumPicksAttributes::__invoke.
   Files > 600 lines: ConfidenceContest 796, SyncScoresService 716, BoxScoreService 616.
3. Watch items: ResetData without a transaction; Team/Week index unpaginated (admin asks page[limit]=500).
   Migration 2026_10_03_000001's no-op down is ACCEPTED by the Command Center (a data repair can't be reversed safely; one-line comment in down()).
4. Re-run pre-floxum (aim: zero findings), ship-check (3 DBs, N+1, phones), npm ci + build js/dist, browser pass again.
5. Send the Command Center the commit range + pre-floxum score + test results; wait for approval.
6. BEFORE the release (readers first): change showcase/seeds/65-picks.php to work with BOTH APIs: POST /picks-picks,
   and on a 404 (Picks before this release) fall back to POST /picks/submit. Commit only that file in ~/github/showcase.
7. After release: bin/deploy showcase,
   dev, then fbsfb (fbsfb2_app, composer update ernestdefoe/picks, migrate, cache:clear, php-fpm then horizon restart).
   ScoreAggregator::recalculateUserScore() is kept for the showcase seed.
