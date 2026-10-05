import app from 'flarum/forum/app';
import UserPage from 'flarum/forum/components/UserPage';
import type Mithril from 'mithril';
import PicksSkeleton from './PicksSkeleton';
import extractText from 'flarum/common/utils/extractText';

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

/** One scored Confidence row: a week, a season, or all time. */
interface C10Line {
  total_points: number;
  total_picks: number;
  correct_picks: number;
  accuracy: number;
  rank: number | null;
  total_players: number;
  tiebreak_diff?: number | null;
}

interface C10Week extends C10Line {
  week_id: number;
  week_name: string;
  is_current: boolean;
}

interface C10Season {
  season_id: number;
  name: string;
  year: number;
  is_current: boolean;
  stats: C10Line;
  weeks: C10Week[];
}

interface C10History {
  enabled: boolean;
  alltime: C10Line | null;
  seasons: C10Season[];
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

  // The Confidence contest's record, when the forum runs one
  private c10: C10History | null = null;
  private c10Loading = false;
  private c10Failed = false;
  private c10Expanded: Set<number> = new Set();

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
      this.loadConfidence(cached.id());
      return;
    }

    app.store.find('users', slug, { bySlug: true } as any).then((user: any) => {
      this.user = user;
      app.current.set('user', user);
      this.loadScores(user.id());
      this.loadHistory(user.id());
      this.loadConfidence(user.id());
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

  /**
   * 🚨 Asked for only when the forum runs the contest — read here, not when the
   * module loads, because app.forum is not there yet at that point.
   */
  private loadConfidence(userId: string | number) {
    if (this.c10Loading || !app.forum.attribute('picksC10Enabled')) return;
    this.c10Loading = true;
    this.c10Failed = false;

    app.request<C10History>({
      method: 'GET',
      url: app.forum.attribute<string>('apiUrl') + '/picks/confidence/user-history',
      params: { user_id: userId },
    }).then((data) => {
      this.c10        = data;
      this.c10Loading = false;
      const current = data.seasons.find(s => s.is_current) || data.seasons[0];
      if (current) this.c10Expanded.add(current.season_id);
      m.redraw();
    }).catch(() => {
      this.c10Loading = false;
      this.c10Failed  = true;
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

        {app.forum.attribute('picksC10Enabled') && this.renderConfidence()}
      </div>
    );
  }

  // ── Confidence contest ────────────────────────────────────────────────────

  /**
   * The member's Confidence record: the same four cards as the full board's
   * header, for all time, then a card per season they played with its weeks
   * — the same pieces as the history above, plus the tiebreaker distance,
   * which is what separates equal points in this contest.
   */
  private renderConfidence(): Mithril.Children {
    const c10 = this.c10;
    const title = app.translator.trans('ernestdefoe-picks.forum.confidence.tab', {
      count: app.forum.attribute('picksC10Games') || 10,
    });

    let body: Mithril.Children;

    if (this.c10Loading || (!c10 && !this.c10Failed)) {
      body = <PicksSkeleton surface="c10-profile" fallback={240} rows={3} variant="rows" />;
    } else if (this.c10Failed || !c10) {
      body = <div className="Picks-profile-empty">{tp('load_failed')}</div>;
    } else if (!c10.alltime) {
      body = <div className="Picks-profile-empty">{tp('c10_empty')}</div>;
    } else {
      const a = c10.alltime;
      body = (
        <div className="Picks-profile-c10">
          <div className="Picks-profile-grid">
            {this.statCard('fas fa-sort-amount-down', String(a.total_picks), t('picks_total'),
              t('correct_wrong', { correct: a.correct_picks, wrong: a.total_picks - a.correct_picks }))}
            {this.statCard('fas fa-bullseye', `${Math.round(a.accuracy)}%`, t('accuracy'))}
            {this.statCard('fas fa-trophy', a.rank != null ? `#${a.rank}` : '—', t('rank'),
              a.rank != null && a.total_players > 0 ? t('of_players', { count: a.total_players }) : null)}
            {this.statCard('fas fa-star', String(a.total_points), t('points'))}
          </div>
          <div className="Picks-history-stack">
            {c10.seasons.map(season => this.renderC10Season(season))}
          </div>
        </div>
      );
    }

    return [
      <div className="Picks-profile-sectionLabel">{title}</div>,
      body,
    ];
  }

  private renderC10Season(season: C10Season): Mithril.Children {
    const open  = this.c10Expanded.has(season.season_id);
    const stats = season.stats;

    return (
      <div className="Picks-season-card" key={'c10-' + season.season_id}>
        <button
          type="button"
          className="Picks-season-header"
          aria-expanded={open ? 'true' : 'false'}
          onclick={() => {
            if (open) this.c10Expanded.delete(season.season_id);
            else this.c10Expanded.add(season.season_id);
          }}
        >
          <div className="Picks-season-left">
            <span className={`Picks-season-badge ${season.is_current ? 'Picks-season-badge--current' : 'Picks-season-badge--past'}`}>
              {season.year}
            </span>
            <div>
              <div className="Picks-season-title">
                {season.name}
                {season.is_current && <span className="Picks-season-openBadge">{tp('in_progress')}</span>}
              </div>
              <div className="Picks-season-meta">
                {tp('season_meta', { weeks: season.weeks.length, picks: stats.total_picks })}
              </div>
            </div>
          </div>

          <div className="Picks-season-right">
            <div className="Picks-season-stat">
              <div className="Picks-season-statVal">{tp('points_value', { count: stats.total_points })}</div>
              <div className="Picks-season-statLbl">{tp('points')}</div>
            </div>
            <div className="Picks-season-stat">
              <div className="Picks-season-statVal">{stats.accuracy.toFixed(0)}%</div>
              <div className="Picks-season-statLbl">{tp('accuracy')}</div>
            </div>
            <div className="Picks-season-stat">
              <div className="Picks-season-statVal">{stats.rank != null ? `#${stats.rank}` : '—'}</div>
              <div className="Picks-season-statLbl">{tp(season.is_current ? 'rank' : 'final_rank')}</div>
            </div>
            <span className={`Picks-season-chevron ${open ? 'Picks-season-chevron--open' : ''}`} aria-hidden="true">
              &#8964;
            </span>
          </div>
        </button>

        {open && (
          <div className="Picks-season-body">
            <table className="Picks-week-table Picks-week-table--c10">
              <thead>
                <tr>
                  <th>{tp('col_week')}</th>
                  <th className="Picks-week-table-r Picks-week-col--wl">{tp('col_won')}</th>
                  <th className="Picks-week-table-r Picks-week-col--wl">{tp('col_lost')}</th>
                  <th className="Picks-week-table-r">{tp('col_accuracy')}</th>
                  <th className="Picks-week-table-r">{tp('col_points')}</th>
                  <th className="Picks-week-table-r" title={extractText(app.translator.trans('ernestdefoe-picks.forum.confidence.col_tiebreaker_title'))}>
                    {tp('col_tiebreaker')}
                  </th>
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
                    <td className="Picks-week-table-r Picks-week-col--wl">{week.correct_picks}</td>
                    <td className="Picks-week-table-r Picks-week-col--wl">{week.total_picks - week.correct_picks}</td>
                    <td className="Picks-week-table-r">
                      <span className={`Picks-profile-accPill ${accClass(week.accuracy)}`}>{week.accuracy.toFixed(0)}%</span>
                    </td>
                    <td className="Picks-week-table-r"><strong>{week.total_points}</strong></td>
                    <td className="Picks-week-table-r Picks-week-rank">
                      {week.tiebreak_diff != null ? `±${week.tiebreak_diff}` : '—'}
                    </td>
                    <td className="Picks-week-table-r Picks-week-rank">{week.rank != null ? `#${week.rank}` : '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="Picks-season-footer">
              <span>{tp('footer_picks', { count: stats.total_picks })}</span>
              <span>{tp('footer_record', { correct: stats.correct_picks, wrong: stats.total_picks - stats.correct_picks })}</span>
              <span>{tp('footer_points', { count: stats.total_points })}</span>
            </div>
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
