import app from 'flarum/forum/app';
import type {
  Game,
  WeekInfo,
  WeeksMeta,
  LeaderboardEntry,
  LeaderboardHistorySeason,
  LeaderboardContext,
} from './types';

/**
 * Shared state + data-loading/mutation for the picks page tabs.
 *
 * Owns every piece of page state (weeks, games, leaderboard, history,
 * off-season context) and the requests that populate it, so the four tab
 * components stay presentational and PicksPage stays a thin orchestrator. Each
 * mutating method triggers its own m.redraw().
 */
export default class PicksState {
  activeTab: string = 'matches';
  weeks: WeekInfo[] = [];
  weeksLoaded: boolean = false;
  currentWeekId: number | null = null;
  weekOpen: boolean = false;
  games: Game[] = [];
  gamesLoading: boolean = false;
  submitting: Record<number, boolean> = {};
  leaderboard: LeaderboardEntry[] = [];
  lbLoading: boolean = false;
  lbScope: string = 'week';
  seasonId: number | null = null;
  weeksMeta: WeeksMeta = {};

  lbHistory: LeaderboardHistorySeason[] = [];
  lbHistoryLoading: boolean = false;
  lbHistoryExpandedSeasons: Set<number> = new Set();

  lbContext: LeaderboardContext | null = null;
  lbContextLoading: boolean = false;

  /** Load weeks, pick the active week, then load its games. */
  init(weekIdParam: number): void {
    app.store
      .find<any[]>('picks-weeks')
      .then((weeks: any[]) => {
        this.weeks = weeks
          .map((w: any) => ({
            id: parseInt(String(w.id())),
            name: w.name(),
            week_number: w.weekNumber(),
            season_type: w.seasonType(),
            start_date: w.startDate(),
            end_date: w.endDate(),
            is_open: w.isOpen() ?? false,
          }))
          .sort((a, b) => {
            if (a.season_type !== b.season_type) return a.season_type === 'regular' ? -1 : 1;
            return (a.week_number || 0) - (b.week_number || 0);
          });

        // Also grab season_id from the first week's season relationship
        const firstWeek = app.store.all<any>('picks-weeks')[0];
        if (firstWeek) {
          this.seasonId = firstWeek.seasonId?.() ?? null;
        }

        if (weekIdParam && this.weeks.find((w) => w.id === weekIdParam)) {
          this.currentWeekId = weekIdParam;
        } else {
          // Default to the last open week (highest week number that is open).
          // Handles multiple weeks being open after auto-unlock.
          const openWeeks = this.weeks.filter((w) => w.is_open);
          if (openWeeks.length > 0) {
            this.currentWeekId = openWeeks[openWeeks.length - 1].id;
          } else if (this.weeks.length > 0) {
            this.currentWeekId = this.weeks[this.weeks.length - 1].id;
          }
        }

        if (this.currentWeekId) {
          this.loadGames();
        }

        // Always redraw even if empty — shows the no-schedule message
        this.weeksLoaded = true;
        m.redraw();
      })
      .catch(() => {
        this.weeksLoaded = true;
        m.redraw();
      });
  }

  loadGames(): void {
    if (!this.currentWeekId) return;

    this.gamesLoading = true;
    m.redraw();

    app
      .request<{ data: Game[]; meta: any }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/my-picks',
        params: { week_id: this.currentWeekId },
      })
      .then((r) => {
        this.games = r.data || [];
        this.weeksMeta = r.meta || {};
        this.weekOpen = r.meta?.week_open ?? false;
        this.gamesLoading = false;
        m.redraw();
      })
      .catch(() => {
        this.gamesLoading = false;
        m.redraw();
      });
  }

  loadLeaderboard(): void {
    const isActive = !!this.currentWeekId || !!this.seasonId;

    // If no active week/season, fetch context first to check off-season retention
    if (!isActive && !this.lbContext && !this.lbContextLoading) {
      this.loadLeaderboardContext(() => {
        this.loadLeaderboard();
      });
      return;
    }

    // Resolve which IDs to use — active season or off-season retained IDs
    const weekId = this.currentWeekId ?? this.lbContext?.last_week_id ?? null;
    const seasonId = this.seasonId ?? this.lbContext?.last_season_id ?? null;
    const isOffSeason = this.lbContext?.is_off_season ?? false;
    const retentionExpired = this.lbContext?.retention_expired ?? false;

    // Week/season scopes with no IDs and retention expired — show off-season state
    if ((this.lbScope === 'week' || this.lbScope === 'season') && isOffSeason && retentionExpired) {
      this.leaderboard = [];
      this.lbLoading = false;
      m.redraw();
      return;
    }

    // Week/season scopes with no IDs and no off-season data — no schedule yet
    if (this.lbScope === 'week' && !weekId) {
      this.leaderboard = [];
      this.lbLoading = false;
      m.redraw();
      return;
    }
    if (this.lbScope === 'season' && !seasonId) {
      this.leaderboard = [];
      this.lbLoading = false;
      m.redraw();
      return;
    }

    this.lbLoading = true;
    m.redraw();

    const params: Record<string, any> = { scope: this.lbScope, limit: 25 };
    if (this.lbScope === 'week' && weekId) params.week_id = weekId;
    if (this.lbScope === 'season' && seasonId) params.season_id = seasonId;

    app
      .request<{ data: LeaderboardEntry[]; meta: any }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/leaderboard',
        params,
      })
      .then((r) => {
        this.leaderboard = r.data || [];
        this.lbLoading = false;
        m.redraw();
      })
      .catch(() => {
        this.lbLoading = false;
        m.redraw();
      });
  }

  loadLeaderboardContext(onComplete: () => void): void {
    if (this.lbContextLoading) return;
    this.lbContextLoading = true;

    app
      .request<LeaderboardContext>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/leaderboard-context',
      })
      .then((r) => {
        this.lbContext = r;
        this.lbContextLoading = false;
        onComplete();
      })
      .catch(() => {
        this.lbContextLoading = false;
        onComplete();
      });
  }

  loadLeaderboardHistory(): void {
    if (this.lbHistoryLoading || this.lbHistory.length > 0) return;
    this.lbHistoryLoading = true;
    m.redraw();

    app
      .request<{ seasons: LeaderboardHistorySeason[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/leaderboard-history',
      })
      .then((r) => {
        this.lbHistory = r.seasons || [];
        this.lbHistoryLoading = false;
        if (this.lbHistory.length > 0) {
          this.lbHistoryExpandedSeasons.add(this.lbHistory[0].season_id);
        }
        m.redraw();
      })
      .catch(() => {
        this.lbHistoryLoading = false;
        m.redraw();
      });
  }

  submitPick(game: Game, outcome: 'home' | 'away'): void {
    if (!app.session.user) {
      m.route.set(app.route('login'));
      return;
    }
    if (!game.can_pick) return;
    if (this.submitting[game.id]) return;

    // Clicking the already-selected team removes the pick
    if (game.my_pick?.selected_outcome === outcome) {
      this.deletePick(game);
      return;
    }

    const prev = game.my_pick ? game.my_pick.selected_outcome : null;
    if (!game.my_pick) {
      game.my_pick = {
        id: 0,
        selected_outcome: outcome,
        is_correct: null,
        confidence: null,
      };
    } else {
      game.my_pick.selected_outcome = outcome;
    }
    this.submitting[game.id] = true;
    m.redraw();

    app
      .request<{
        status: string;
        pick_id: number;
        selected_outcome: string;
        confidence: number | null;
      }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/picks/submit',
        body: { event_id: game.id, selected_outcome: outcome },
      })
      .then((r) => {
        if (game.my_pick) game.my_pick.id = r.pick_id;
        // Increment picked count only when this is a new pick, not changing an existing one
        if (prev === null) {
          this.weeksMeta.picked = (this.weeksMeta.picked || 0) + 1;
        }
        this.submitting[game.id] = false;
        m.redraw();
      })
      .catch(() => {
        if (game.my_pick) game.my_pick.selected_outcome = prev as 'home' | 'away';
        this.submitting[game.id] = false;
        m.redraw();
      });
  }

  deletePick(game: Game): void {
    if (!game.my_pick || this.submitting[game.id]) return;

    // Optimistic update
    const prevPick = game.my_pick;
    game.my_pick = null;
    this.submitting[game.id] = true;
    m.redraw();

    app
      .request({
        method: 'DELETE',
        url: `${app.forum.attribute('apiUrl')}/picks/events/${game.id}/pick`,
      })
      .then(() => {
        this.submitting[game.id] = false;
        // Update the week meta picked count
        if (this.weeksMeta.picked && this.weeksMeta.picked > 0) {
          this.weeksMeta.picked--;
        }
        m.redraw();
      })
      .catch(() => {
        // Revert on failure
        game.my_pick = prevPick;
        this.submitting[game.id] = false;
        m.redraw();
      });
  }

  submitConfidence(game: Game, confidence: number): void {
    if (!game.my_pick || !game.can_pick) return;
    game.my_pick.confidence = confidence;
    m.redraw();

    app
      .request({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/picks/submit',
        body: {
          event_id: game.id,
          selected_outcome: game.my_pick.selected_outcome,
          confidence,
        },
      })
      .catch(() => m.redraw());
  }

  currentWeek(): WeekInfo | undefined {
    return this.weeks.find((w) => w.id === this.currentWeekId);
  }

  prevWeek(): void {
    const idx = this.weeks.findIndex((w) => w.id === this.currentWeekId);
    if (idx > 0) {
      this.currentWeekId = this.weeks[idx - 1].id;
      this.loadGames();
    }
  }

  nextWeek(): void {
    const idx = this.weeks.findIndex((w) => w.id === this.currentWeekId);
    if (idx < this.weeks.length - 1) {
      this.currentWeekId = this.weeks[idx + 1].id;
      this.loadGames();
    }
  }
}
