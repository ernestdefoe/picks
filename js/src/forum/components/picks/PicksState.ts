import app from 'flarum/forum/app';
import type {
  Game,
  WeekInfo,
  WeeksMeta,
  LeaderboardEntry,
  LeaderboardHistorySeason,
  LeaderboardContext,
} from './types';
import ConfidenceState from './ConfidenceState';

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
  /** The week being VIEWED — not necessarily this week; see thisWeekId. */
  currentWeekId: number | null = null;
  /** This week, as the server works it out (WeekResource `isCurrent`). */
  thisWeekId: number | null = null;
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

  /** The Confidence contest, beside the full board. */
  c10 = new ConfidenceState();
  /** Which contest the Leaderboard and History tabs are showing. */
  lbContest: 'main' | 'c10' = 'main';
  historyContest: 'main' | 'c10' = 'main';

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
            is_current: w.isCurrent() ?? false,
            season_id: w.seasonId?.() ?? null,
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

        this.thisWeekId = this.weeks.find((w) => w.is_current)?.id ?? null;

        if (weekIdParam && this.weeks.find((w) => w.id === weekIdParam)) {
          this.currentWeekId = weekIdParam;
        } else {
          this.currentWeekId = this.landingWeekId();
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

  /**
   * The pick a guest made just before signing up, now that they can have it.
   *
   * 🚨 Cleared whether or not it can be applied. A pick left in storage would
   * be re-applied on some later visit to a game that had long since kicked off
   * — a choice the member never made, appearing days afterwards.
   */
  applyPendingPick(): void {
    if (!app.session.user) return;

    let pending: { game?: number; outcome?: 'home' | 'away'; week?: number } | null = null;

    try {
      const raw = sessionStorage.getItem(PicksState.PENDING);
      if (!raw) return;
      sessionStorage.removeItem(PicksState.PENDING);
      pending = JSON.parse(raw);
    } catch (e) {
      return;
    }

    if (!pending?.game || !pending.outcome) return;

    const game = this.games.find((g) => g.id === pending!.game);

    // Still open, still theirs to make: anything else is silently dropped.
    if (game && game.can_pick && !game.my_pick) {
      this.submitPick(game, pending.outcome);
    }
  }

  loadGames(): void {
    if (!this.currentWeekId) return;

    // The week arrows move both contests together.
    if (this.activeTab === 'confidence' || this.c10.board) {
      this.c10.load(this.currentWeekId);
    }

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
        this.applyPendingPick();
        m.redraw();
      })
      .catch(() => {
        this.gamesLoading = false;
        m.redraw();
      });
  }

  loadLeaderboard(): void {
    if (this.lbContest === 'c10') {
      const scope = this.lbScope === 'season' || this.lbScope === 'alltime' ? this.lbScope : 'week';
      this.lbScope = scope;
      this.c10.loadLeaderboard(scope, this.currentWeekId, this.seasonId ?? this.currentWeek()?.season_id ?? null);
      return;
    }

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

  /** Where a guest's intended pick waits while they make an account. */
  static readonly PENDING = 'picks.pendingPick';

  submitPick(game: Game, outcome: 'home' | 'away'): void {
    if (!app.session.user) {
      /*
       * 🚨 This threw. `app.route('login')` is a route name Flarum 2 does not
       * have, so clicking a team as a guest raised "Route 'login' does not
       * exist" and the page showed the generic "something went wrong" banner.
       * The single moment the pick'em has to turn a visitor into a member was
       * a JavaScript error.
       *
       * 🚨 The pick is KEPT across the sign-up. Flarum reloads the page once an
       * account is made, so an in-memory choice would be gone and the new
       * member would land on a board that had forgotten what they came to do.
       * sessionStorage survives that one reload and nothing more, which is
       * exactly as long as it is wanted.
       */
      try {
        sessionStorage.setItem(
          PicksState.PENDING,
          JSON.stringify({ game: game.id, outcome, week: this.currentWeekId })
        );
      } catch (e) {
        // A browser refusing storage is not a reason to refuse the sign-up.
      }

      /*
       * 🚨 A lazy import, which is how core itself opens this. Flarum 2 ships
       * its modals as separate chunks, so a static `import SignUpModal from
       * 'flarum/forum/components/SignUpModal'` resolves to undefined and the
       * click dies on "Cannot read properties of undefined (reading
       * 'prototype')" — measured, after the first fix had already stashed the
       * pick and looked like it worked.
       */
      app.modal.show(() => import('flarum/forum/components/SignUpModal'));

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
    // Capture the previous value so a failed request can roll back the
    // optimistic update instead of leaving the wrong confidence on screen.
    const prevConfidence = game.my_pick.confidence;
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
      .catch(() => {
        if (game.my_pick) game.my_pick.confidence = prevConfidence;
        m.redraw();
      });
  }

  /**
   * Where the board opens without a week in the URL.
   *
   * 🚨 This used to be the LAST open week, so a board that opens two weeks at
   * a time sent everybody to next week while this week was still being
   * played. "This week" is now the server's answer (CurrentWeek::pick), and
   * the "This week" button returns to the same one.
   */
  landingWeekId(): number | null {
    if (this.weeks.length === 0) return null;

    const thisWeek = this.weeks.find((w) => w.id === this.thisWeekId);

    if (app.forum.attribute('picksDefaultWeekView') === 'first') {
      const season = thisWeek?.season_id ?? null;
      const first = this.weeks.find((w) => season === null || w.season_id === season);
      if (first) return first.id;
    }

    if (thisWeek) return thisWeek.id;

    // A server too old to say: the earliest open week, never the last.
    const open = this.weeks.find((w) => w.is_open);
    return (open ?? this.weeks[this.weeks.length - 1]).id;
  }

  /** Back to this week from wherever the visitor has browsed to. */
  goToThisWeek(): void {
    if (!this.thisWeekId || this.thisWeekId === this.currentWeekId) return;
    this.currentWeekId = this.thisWeekId;
    this.loadGames();
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
