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

const t = (key: string, params: Record<string, unknown> = {}) =>
  app.translator.trans(`ernestdefoe-picks.forum.profile_stats.${key}`, params);

/** The profile page's own words, beside the stat cards' above. */
const tp = (key: string, params: Record<string, unknown> = {}) =>
  app.translator.trans(`ernestdefoe-picks.forum.profile.${key}`, params);

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
      this.historyError   = 'load_failed';
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
              {tp('tab_' + tab)}
            </button>
          ))}
        </div>

        {this.activeTab === 'alltime' && this.renderScope(this.scores?.alltime ?? null, 'alltime')}
        {this.activeTab === 'season'  && this.renderScope(this.scores?.season  ?? null, 'season')}
        {this.activeTab === 'week'    && this.renderScope(this.scores?.week    ?? null, 'week')}

        {/* ── History stack ── */}
        <div className="Picks-profile-sectionLabel">{tp('history_heading')}</div>

        {this.historyLoading && <PicksSkeleton surface="history" fallback={295} rows={5} variant="rows" />}

        {this.historyError && (
          <div className="Picks-profile-empty">{tp(this.historyError)}</div>
        )}

        {!this.historyLoading && !this.historyError && this.history && (
          this.history.seasons.length === 0
            ? <div className="Picks-profile-empty">{tp('no_history')}</div>
            : <div className="Picks-history-stack">
                {this.history.seasons.map(season => this.renderSeasonCard(season))}
              </div>
        )}
      </div>
    );
  }

  private renderScope(s: ScopeStats | null, tab: 'alltime' | 'season' | 'week'): Mithril.Children {
    if (this.scoresLoading) {
      return <div className="Picks-profile-loading">{tp('loading')}</div>;
    }

    if (!s || s.total_picks === 0) {
      return <div className="Picks-profile-empty">{tp('empty_' + tab)}</div>;
    }

    const wrongPicks = s.total_picks - s.correct_picks;
    const history    = this.history;
    const alltime    = history?.alltime ?? null;

    return (
      <div className="Picks-profile-scope">
        <div className="Picks-profile-grid">

          {this.statCard('fas fa-football', String(s.total_picks),
            t(tab === 'week' ? 'picks_week' : tab === 'season' ? 'picks_season' : 'picks_total'),
            t('correct_wrong', { correct: s.correct_picks, wrong: wrongPicks }))}

          {this.statCard('fas fa-bullseye', fmt(s.accuracy, '%'), t('accuracy'))}

          {this.statCard('fas fa-trophy', s.rank != null ? `#${s.rank}` : '—', t('rank'),
            s.rank != null && s.total_players > 0 ? t('of_players', { count: s.total_players }) : null)}

          {this.statCard('fas fa-star', String(s.total_points), t('points'))}

          {tab === 'alltime' && alltime?.best_week && this.statCard('fas fa-medal', alltime.best_week.week_name,
            t('best_week', { year: alltime.best_week.season_year }),
            `${alltime.best_week.correct_picks}/${alltime.best_week.total_picks} · ${alltime.best_week.accuracy.toFixed(0)}%`,
            'PicksStat--accent PicksStat--text')}

          {tab === 'alltime' && alltime != null && this.statCard('fas fa-fire', String(alltime.longest_streak),
            t('longest_streak'), t('streak_sub'), 'PicksStat--accent')}

        </div>
      </div>
    );
  }

  /**
   * One stat on the profile header.
   *
   * 🚨 Styled HERE, by Picks. These cards were written against the class names
   * of a separate StatCards extension and borrowed its styling; on a forum
   * without it (every forum, now) they rendered as bare stacked text.
   */
  private statCard(icon: string, value: string, label: Mithril.Children, sub: Mithril.Children = null, extra = ''): Mithril.Children {
    return (
      <div className={`PicksStat ${extra}`}>
        <div className="PicksStat-icon"><i className={icon} aria-hidden="true" /></div>
        <div className="PicksStat-body">
          <div className="PicksStat-value">{value}</div>
          <div className="PicksStat-label">{label}</div>
          {sub ? <div className="PicksStat-sub">{sub}</div> : null}
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
                  <span className="Picks-season-openBadge">{tp('in_progress')}</span>
                )}
              </div>
              <div className="Picks-season-meta">
                {tp('season_meta', { weeks: season.weeks.length, picks: stats ? stats.total_picks : 0 })}
              </div>
            </div>
          </div>

          <div className="Picks-season-right">
            {stats && stats.total_picks > 0 ? (
              <>
                <div className="Picks-season-stat">
                  <div className="Picks-season-statVal">{tp('points_value', { count: stats.total_points })}</div>
                  <div className="Picks-season-statLbl">{tp('points')}</div>
                </div>
                <div className="Picks-season-stat">
                  <div className="Picks-season-statVal">{stats.accuracy.toFixed(0)}%</div>
                  <div className="Picks-season-statLbl">{tp('accuracy')}</div>
                </div>
                <div className="Picks-season-stat">
                  <div className="Picks-season-statVal">
                    {stats.rank != null ? `#${stats.rank}` : '—'}
                  </div>
                  <div className="Picks-season-statLbl">{tp(season.is_current ? 'rank' : 'final_rank')}</div>
                </div>
              </>
            ) : (
              <div className="Picks-season-stat">
                <div className="Picks-season-statLbl">{tp('no_picks')}</div>
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
              <div className="Picks-profile-empty" style="padding: 1rem 1.1rem;">{tp('empty_season')}</div>
            ) : (
              <>
                <table className="Picks-week-table">
                  <thead>
                    <tr>
                      <th>{tp('col_week')}</th>
                      <th className="Picks-week-table-r">{tp('col_won')}</th>
                      <th className="Picks-week-table-r">{tp('col_lost')}</th>
                      <th className="Picks-week-table-r">{tp('col_accuracy')}</th>
                      <th className="Picks-week-table-r">{tp('col_points')}</th>
                      <th className="Picks-week-table-r">{tp('col_rank')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {season.weeks.map(week => (
                      <tr key={String(week.week_id)}>
                        <td>
                          <span className="Picks-week-name">{week.week_name}</span>
                          {week.is_current && <span className="Picks-season-openBadge">{tp('active')}</span>}
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
                    <span>{tp('footer_picks', { count: stats.total_picks })}</span>
                    <span>{tp('footer_record', { correct: stats.correct_picks, wrong: stats.total_picks - stats.correct_picks })}</span>
                    <span>{tp('footer_points', { count: stats.total_points })}</span>
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
