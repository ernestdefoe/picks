import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import PicksSkeleton, { measure } from './PicksSkeleton';

// ── Interfaces ────────────────────────────────────────────────────────────────

interface ScopeStats {
  total_picks: number;
  correct_picks: number;
  total_points: number;
  accuracy: number;
  rank: number | null;
  total_players: number;
}

interface UserScores {
  current_week_name: string | null;
  alltime: ScopeStats | null;
  season: ScopeStats | null;
  week: ScopeStats | null;
}

interface BestWeek {
  week_name: string;
  season_year: number;
  accuracy: number;
  correct_picks: number;
  total_picks: number;
  total_points: number;
}

interface AlltimeWithExtras extends ScopeStats {
  longest_streak: number;
  best_week: BestWeek | null;
}

interface WeekHistory {
  week_id: number;
  week_name: string;
  week_number: number;
  is_current: boolean;
  total_picks: number;
  correct_picks: number;
  total_points: number;
  accuracy: number;
  rank: number | null;
}

interface SeasonHistory {
  season_id: number;
  name: string;
  year: number;
  is_current: boolean;
  stats: ScopeStats | null;
  weeks: WeekHistory[];
}

interface UserHistory {
  alltime: AlltimeWithExtras | null;
  seasons: SeasonHistory[];
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function fmt(n: number | null | undefined, suffix = ''): string {
  if (n == null) return '—';
  return `${n}${suffix}`;
}

function accClass(accuracy: number): string {
  if (accuracy >= 75) return 'Picks-profile-accPill--high';
  if (accuracy >= 50) return 'Picks-profile-accPill--med';
  return 'Picks-profile-accPill--low';
}

// ── Component ─────────────────────────────────────────────────────────────────

export default class UserPicksPage extends UserPage {
  // Scores for the top stat cards (ported from StatCards)
  private scores: UserScores | null = null;
  private scoresLoading = false;
  private activeTab: 'alltime' | 'season' | 'week' = 'alltime';

  // History stack
  private history: UserHistory | null = null;
  private historyLoading = false;
  private historyError: string | null = null;
  private expandedSeasons: Set<number> = new Set();

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const username = m.route.param('username');
    if (username) this.loadUserThenData(username);
  }

  // ── Data loading ──────────────────────────────────────────────────────────

  private loadUserThenData(slug: string) {
    const cached = app.store.all('users').find(
      (u: any) =>
        (u.slug?.() || '').toLowerCase() === slug.toLowerCase() ||
        (u.username?.() || '').toLowerCase() === slug.toLowerCase()
    ) as any;

    if (cached?.id?.()) {
      this.user = cached;
      app.current.set('user', cached);
      this.loadScores(cached.id());
      this.loadHistory(cached.id());
      return;
    }

    app.store.find('users', slug, { bySlug: true } as any).then((user: any) => {
      this.user = user;
      app.current.set('user', user);
      this.loadScores(user.id());
      this.loadHistory(user.id());
      m.redraw();
    }).catch(() => m.redraw());
  }

  private loadScores(userId: string | number) {
    if (this.scoresLoading) return;
    this.scoresLoading = true;

    app.request<UserScores>({
      method: 'GET',
      url: app.forum.attribute<string>('apiUrl') + '/picks/user-scores',
      params: { user_id: userId },
    }).then((data) => {
      this.scores        = data;
      this.scoresLoading = false;
      m.redraw();
    }).catch(() => {
      this.scoresLoading = false;
      m.redraw();
    });
  }

  private loadHistory(userId: string | number) {
    if (this.historyLoading) return;
    this.historyLoading = true;
    this.historyError   = null;

    app.request<UserHistory>({
      method: 'GET',
      url: app.forum.attribute<string>('apiUrl') + '/picks/user-history',
      params: { user_id: userId },
    }).then((data) => {
      this.history        = data;
      this.historyLoading = false;

      // Auto-expand the current season
      const currentSeason = data.seasons.find(s => s.is_current);
      if (currentSeason) {
        this.expandedSeasons.add(currentSeason.season_id);
      }

      m.redraw();
    }).catch(() => {
      this.historyLoading = false;
      this.historyError   = 'Could not load pick history.';
      m.redraw();
    });
  }

  // activeKey used by Avocado sidebar nav to mark active item
  activeKey() { return 'picks-history'; }

  // ── Render ────────────────────────────────────────────────────────────────

  content(): Mithril.Children {
    return (
      <div className="Picks-profile">

        {/* ── Top stat cards (ported from StatCards) ── */}
        <div className="Picks-profile-tabRow">
          {(['alltime', 'season', 'week'] as const).map(tab => (
            <button
              className={`Picks-profile-tab${this.activeTab === tab ? ' Picks-profile-tab--active' : ''}`}
              onclick={() => { this.activeTab = tab; m.redraw(); }}
            >
              {tab === 'alltime' ? 'All time' : tab === 'season' ? 'This season' : 'This week'}
            </button>
          ))}
        </div>

        {this.activeTab === 'alltime' && this.renderScope(this.scores?.alltime ?? null, 'alltime')}
        {this.activeTab === 'season'  && this.renderScope(this.scores?.season  ?? null, 'season')}
        {this.activeTab === 'week'    && this.renderScope(this.scores?.week    ?? null, 'week')}

        {/* ── History stack ── */}
        <div className="Picks-profile-sectionLabel">Pick History</div>

        {this.historyLoading && <PicksSkeleton surface="history" fallback={295} rows={5} variant="rows" />}

        {this.historyError && (
          <div className="Picks-profile-empty">{this.historyError}</div>
        )}

        {!this.historyLoading && !this.historyError && this.history && (
          this.history.seasons.length === 0
            ? <div className="Picks-profile-empty">No season history yet.</div>
            : <div className="Picks-history-stack">
                {this.history.seasons.map(season => this.renderSeasonCard(season))}
              </div>
        )}
      </div>
    );
  }

  private renderScope(s: ScopeStats | null, tab: 'alltime' | 'season' | 'week'): Mithril.Children {
    if (this.scoresLoading) {
      return <div className="Picks-profile-loading">Loading…</div>;
    }

    if (!s || s.total_picks === 0) {
      if (tab === 'week') {
        return <div className="Picks-profile-empty">No results recorded this week yet.</div>;
      }
      if (tab === 'season') {
        return <div className="Picks-profile-empty">No results recorded this season yet.</div>;
      }
      return <div className="Picks-profile-empty">No results recorded yet.</div>;
    }

    const wrongPicks = s.total_picks - s.correct_picks;
    const history    = this.history;
    const alltime    = history?.alltime ?? null;

    return (
      <div className="Picks-profile-scope">
        <div className="Picks-profile-grid">

          {/* Picks */}
          <div className="StatCards-card">
            <div className="StatCards-card-icon">
              <i className="fas fa-football" aria-hidden="true" />
            </div>
            <div className="StatCards-card-value">{s.total_picks}</div>
            <div className="StatCards-card-label">
              {tab === 'week' ? 'Picks this week' : tab === 'season' ? 'Picks this season' : 'Total picks'}
            </div>
            <div className="StatCards-profile-sub">{s.correct_picks} correct · {wrongPicks} wrong</div>
          </div>

          {/* Accuracy */}
          <div className="StatCards-card">
            <div className="StatCards-card-icon StatCards-card-icon--primary">
              <i className="fas fa-bullseye" aria-hidden="true" />
            </div>
            <div className="StatCards-card-value">{fmt(s.accuracy, '%')}</div>
            <div className="StatCards-card-label">Accuracy</div>
          </div>

          {/* Rank */}
          <div className="StatCards-card">
            <div className="StatCards-card-icon">
              <i className="fas fa-trophy" aria-hidden="true" />
            </div>
            <div className="StatCards-card-value">
              {s.rank != null ? `#${s.rank}` : '—'}
            </div>
            <div className="StatCards-card-label">Rank</div>
            {s.rank != null && s.total_players > 0 && (
              <div className="StatCards-profile-sub">of {s.total_players} players</div>
            )}
          </div>

          {/* Total points — all tabs */}
          <div className="StatCards-card">
            <div className="StatCards-card-icon">
              <i className="fas fa-star" aria-hidden="true" />
            </div>
            <div className="StatCards-card-value">{s.total_points}</div>
            <div className="StatCards-card-label">Points</div>
          </div>

          {/* Best week — alltime tab only */}
          {tab === 'alltime' && alltime?.best_week && (
            <div className="StatCards-card Picks-profile-card--accent">
              <div className="StatCards-card-icon">
                <i className="fas fa-medal" aria-hidden="true" />
              </div>
              <div className="StatCards-card-value" style="font-size: 18px;">
                {alltime.best_week.week_name}
              </div>
              <div className="StatCards-card-label">Best week · {alltime.best_week.season_year}</div>
              <div className="StatCards-profile-sub">
                {alltime.best_week.correct_picks}/{alltime.best_week.total_picks} · {alltime.best_week.accuracy.toFixed(0)}%
              </div>
            </div>
          )}

          {/* Longest streak — alltime tab only */}
          {tab === 'alltime' && alltime != null && (
            <div className="StatCards-card Picks-profile-card--streak">
              <div className="StatCards-card-icon">
                <i className="fas fa-fire" aria-hidden="true" />
              </div>
              <div className="StatCards-card-value Picks-profile-streakVal">
                {alltime.longest_streak}
              </div>
              <div className="StatCards-card-label">Longest streak</div>
              <div className="StatCards-profile-sub">consecutive correct picks</div>
            </div>
          )}

        </div>
      </div>
    );
  }

  // ── Season card ───────────────────────────────────────────────────────────

  private renderSeasonCard(season: SeasonHistory): Mithril.Children {
    const isExpanded = this.expandedSeasons.has(season.season_id);
    const stats      = season.stats;

    return (
      <div className="Picks-season-card" key={String(season.season_id)}>

        {/* Header row — always visible, click to expand/collapse */}
        <div
          className="Picks-season-header"
          onclick={() => {
            if (isExpanded) {
              this.expandedSeasons.delete(season.season_id);
            } else {
              this.expandedSeasons.add(season.season_id);
            }
            m.redraw();
          }}
        >
          <div className="Picks-season-left">
            <span className={`Picks-season-badge ${season.is_current ? 'Picks-season-badge--current' : 'Picks-season-badge--past'}`}>
              {season.year}
            </span>
            <div>
              <div className="Picks-season-title">
                {season.name}
                {season.is_current && (
                  <span className="Picks-season-openBadge">In progress</span>
                )}
              </div>
              <div className="Picks-season-meta">
                {season.weeks.length} week{season.weeks.length !== 1 ? 's' : ''} · {stats ? stats.total_picks : 0} picks submitted
              </div>
            </div>
          </div>

          <div className="Picks-season-right">
            {stats && stats.total_picks > 0 ? (
              <>
                <div className="Picks-season-stat">
                  <div className="Picks-season-statVal">{stats.total_points} pts</div>
                  <div className="Picks-season-statLbl">Points</div>
                </div>
                <div className="Picks-season-stat">
                  <div className="Picks-season-statVal">{stats.accuracy.toFixed(0)}%</div>
                  <div className="Picks-season-statLbl">Accuracy</div>
                </div>
                <div className="Picks-season-stat">
                  <div className="Picks-season-statVal">
                    {stats.rank != null ? `#${stats.rank}` : '—'}
                  </div>
                  <div className="Picks-season-statLbl">{season.is_current ? 'Rank' : 'Final rank'}</div>
                </div>
              </>
            ) : (
              <div className="Picks-season-stat">
                <div className="Picks-season-statLbl">No picks</div>
              </div>
            )}
            <span className={`Picks-season-chevron ${isExpanded ? 'Picks-season-chevron--open' : ''}`}>
              &#8964;
            </span>
          </div>
        </div>

        {/* Week table — only visible when expanded */}
        {isExpanded && (
          <div className="Picks-season-body">
            {season.weeks.length === 0 ? (
              <div className="Picks-profile-empty" style="padding: 1rem 1.1rem;">No results recorded this season yet.</div>
            ) : (
              <>
                <table className="Picks-week-table">
                  <thead>
                    <tr>
                      <th>Week</th>
                      <th className="Picks-week-table-r">W</th>
                      <th className="Picks-week-table-r">L</th>
                      <th className="Picks-week-table-r">Acc</th>
                      <th className="Picks-week-table-r">Pts</th>
                      <th className="Picks-week-table-r">Rank</th>
                    </tr>
                  </thead>
                  <tbody>
                    {season.weeks.map(week => (
                      <tr key={String(week.week_id)}>
                        <td>
                          <span className="Picks-week-name">{week.week_name}</span>
                          {week.is_current && <span className="Picks-season-openBadge">Active</span>}
                        </td>
                        <td className="Picks-week-table-r">{week.correct_picks}</td>
                        <td className="Picks-week-table-r">{week.total_picks - week.correct_picks}</td>
                        <td className="Picks-week-table-r">
                          <span className={`Picks-profile-accPill ${accClass(week.accuracy)}`}>
                            {week.accuracy.toFixed(0)}%
                          </span>
                        </td>
                        <td className="Picks-week-table-r"><strong>{week.total_points}</strong></td>
                        <td className="Picks-week-table-r Picks-week-rank">
                          {week.rank != null ? `#${week.rank}` : '—'}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>

                {/* Season summary footer */}
                {stats && stats.total_picks > 0 && (
                  <div className="Picks-season-footer">
                    <span><strong>{stats.total_picks}</strong> picks</span>
                    <span><strong>{stats.correct_picks}</strong> correct · <strong>{stats.total_picks - stats.correct_picks}</strong> wrong</span>
                    <span><strong>{stats.total_points}</strong> pts</span>
                  </div>
                )}
              </>
            )}
          </div>
        )}
      </div>
    );
  }
}
