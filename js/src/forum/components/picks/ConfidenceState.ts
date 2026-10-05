import app from 'flarum/forum/app';
import { moveItem } from '../../../common/sortable';
import type { C10Board, C10Game, LeaderboardEntry, LeaderboardHistorySeason } from './types';

/**
 * The member's side of the Confidence contest: one week's games, their
 * winners, their values, the tiebreaker, and the standings.
 *
 * The values work like this. Every game holds a value from 1 to N, no two the
 * same. A LOCKED game keeps the value it was saved with. The unlocked games
 * share out what is left, and their order on screen is their order by value,
 * highest first — so dragging a game up the list is the same thing as giving it
 * a bigger number. Reordering only ever permutes the values the unlocked games
 * already hold, which is what keeps them unique without the member having to
 * think about it.
 *
 * Every change saves itself a moment later; the server checks the same rules
 * again (Confidence\Rules) and a refusal reloads the week as it really is.
 */
export default class ConfidenceState {
  board: C10Board | null = null;
  loading = false;
  weekId: number | null = null;

  /** Unlocked game ids, highest value first. */
  order: number[] = [];
  values: Record<number, number> = {};
  outcomes: Record<number, 'home' | 'away' | null> = {};
  tiebreaker = '';

  saving = false;
  saved = false;
  error: string | null = null;
  private timer: ReturnType<typeof setTimeout> | null = null;

  leaderboard: LeaderboardEntry[] = [];
  lbLoading = false;
  lbLoadedFor = '';
  history: LeaderboardHistorySeason[] = [];
  historyLoading = false;
  historyLoaded = false;

  load(weekId: number | null): void {
    if (!weekId) return;

    this.weekId = weekId;
    this.loading = true;
    m.redraw();

    app
      .request<{ data: C10Board }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/confidence',
        params: { week_id: weekId },
      })
      .then((r) => {
        if (this.weekId !== weekId) return;
        this.adopt(r.data);
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  /** Take the server's board as the truth, and lay the values out from it. */
  adopt(board: C10Board): void {
    this.board = board;

    const n = board.size;
    const games = board.games;
    const unlocked = games.filter((g) => g.can_pick);

    const held = new Set<number>();
    games.filter((g) => !g.can_pick && g.my_pick).forEach((g) => held.add(g.my_pick!.confidence));

    const free: number[] = [];
    for (let v = n; v >= 1; v--) if (!held.has(v)) free.push(v);

    this.values = {};
    this.outcomes = {};

    // Saved values stand where they can.
    const taken = new Set<number>();
    unlocked.forEach((g) => {
      this.outcomes[g.id] = g.my_pick?.selected_outcome ?? null;
      const v = g.my_pick?.confidence;
      if (v && free.includes(v) && !taken.has(v)) {
        this.values[g.id] = v;
        taken.add(v);
      }
    });

    // Everything else takes the biggest value left, biggest game first.
    const left = free.filter((v) => !taken.has(v));
    unlocked
      .filter((g) => this.values[g.id] === undefined)
      .sort((a, b) => a.position - b.position)
      .forEach((g) => {
        const v = left.shift();
        if (v !== undefined) this.values[g.id] = v;
      });

    this.order = unlocked.map((g) => g.id).sort((a, b) => (this.values[b] || 0) - (this.values[a] || 0));
    this.tiebreaker = board.tiebreaker?.guess != null ? String(board.tiebreaker.guess) : '';
  }

  game(id: number): C10Game | undefined {
    return this.board?.games.find((g) => g.id === id);
  }

  lockedGames(): C10Game[] {
    if (!this.board) return [];
    return this.board.games
      .filter((g) => !g.can_pick)
      .sort((a, b) => (b.my_pick?.confidence || 0) - (a.my_pick?.confidence || 0) || a.position - b.position);
  }

  /** Values an unlocked game may take: everything a locked pick does not hold. */
  freeValues(): number[] {
    const held = new Set(this.lockedGames().filter((g) => g.my_pick).map((g) => g.my_pick!.confidence));
    const out: number[] = [];
    for (let v = this.board?.size || 0; v >= 1; v--) if (!held.has(v)) out.push(v);
    return out;
  }

  canPlay(): boolean {
    return !!app.session.user && !!app.forum.attribute('picksCanMakePicks');
  }

  /** Move a game from one place in the list to another. */
  move(from: number, to: number): void {
    if (to < 0 || to >= this.order.length || from === to) return;

    const ranked = this.order.map((id) => this.values[id]).sort((a, b) => b - a);
    this.order = moveItem(this.order, from, to);
    this.order.forEach((id, i) => (this.values[id] = ranked[i]));
    this.changed();
  }

  /** The select fallback: give a game a value, swapping with whoever had it. */
  setValue(id: number, value: number): void {
    const holder = this.order.find((other) => other !== id && this.values[other] === value);
    if (holder !== undefined) this.values[holder] = this.values[id];
    this.values[id] = value;
    this.order = this.order.slice().sort((a, b) => this.values[b] - this.values[a]);
    this.changed();
  }

  pick(id: number, side: 'home' | 'away'): void {
    this.outcomes[id] = this.outcomes[id] === side ? null : side;
    this.changed();
  }

  setTiebreaker(value: string): void {
    this.tiebreaker = value.replace(/[^0-9]/g, '').slice(0, 3);
    this.changed();
  }

  pickedCount(): number {
    const locked = this.lockedGames().filter((g) => g.my_pick).length;
    return locked + this.order.filter((id) => this.outcomes[id]).length;
  }

  private changed(): void {
    this.saved = false;
    this.error = null;
    m.redraw();

    if (this.timer) clearTimeout(this.timer);
    this.timer = setTimeout(() => this.save(), 600);
  }

  save(): void {
    if (!this.board || !this.canPlay()) return;

    const weekId = this.board.week_id;
    const body: Record<string, unknown> = {
      week_id: weekId,
      picks: this.order.map((id) => ({
        event_id: id,
        selected_outcome: this.outcomes[id] || null,
        confidence: this.values[id],
      })),
    };

    if (this.board.tiebreaker?.can_change) {
      body.tiebreaker = this.tiebreaker === '' ? null : parseInt(this.tiebreaker, 10);
    }

    this.saving = true;
    m.redraw();

    app
      .request<{ status: string; data: C10Board }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/picks/confidence',
        body,
        errorHandler: () => {},
      })
      .then((r) => {
        this.saving = false;
        this.saved = true;
        // Keep the member's layout; take only what the server counts.
        if (this.board && r.data) {
          this.board.my_score = r.data.my_score;
          this.board.picked = r.data.picked;
          this.board.frozen = r.data.frozen;
          r.data.games.forEach((g) => {
            const mine = this.game(g.id);
            if (mine) mine.my_pick = g.my_pick;
          });
        }
        m.redraw();
      })
      .catch((e: any) => {
        this.saving = false;
        this.error = e?.response?.code || 'generic';
        m.redraw();
        // The week as it really is — a game may have locked under the member.
        // Not for a bad tiebreaker: nothing else changed, and a reload would
        // throw away the ranking they just made.
        if (this.weekId && this.error !== 'bad_tiebreaker') this.load(this.weekId);
      });
  }

  loadLeaderboard(scope: string, weekId: number | null, seasonId: number | null): void {
    // All time needs no id: it is every season there is.
    const id = scope === 'alltime' ? 0 : scope === 'week' ? weekId : seasonId;
    if (id === null || (scope !== 'alltime' && !id)) {
      this.leaderboard = [];
      return;
    }

    const key = scope + ':' + id;
    this.lbLoadedFor = key;
    this.lbLoading = true;
    m.redraw();

    app
      .request<{ data: LeaderboardEntry[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/confidence/leaderboard',
        params: scope === 'alltime' ? { scope } : scope === 'week' ? { scope, week_id: id } : { scope, season_id: id },
      })
      .then((r) => {
        if (this.lbLoadedFor !== key) return;
        this.leaderboard = r.data || [];
        this.lbLoading = false;
        m.redraw();
      })
      .catch(() => {
        this.lbLoading = false;
        m.redraw();
      });
  }

  loadHistory(): void {
    if (this.historyLoading || this.historyLoaded) return;
    this.historyLoading = true;
    m.redraw();

    app
      .request<{ seasons: LeaderboardHistorySeason[] }>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/confidence/history',
      })
      .then((r) => {
        this.history = r.seasons || [];
        this.historyLoading = false;
        this.historyLoaded = true;
        m.redraw();
      })
      .catch(() => {
        this.historyLoading = false;
        m.redraw();
      });
  }
}
