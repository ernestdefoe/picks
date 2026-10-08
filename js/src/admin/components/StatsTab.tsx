import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import Week from '../../common/models/Week';

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-picks.admin.stats.' + key, params as any);

interface MostPickedTeam {
  name: string;
  abbreviation: string;
  picks: number;
}

interface ContestledGame {
  event_id: number;
  home_team: string;
  away_team: string;
  home_pct: number;
  away_pct: number;
  total: number;
}

interface StatsData {
  participation: {
    total_players: number;
    unique_pickers_this_week: number;
    picks_this_week: number;
    total_games_this_week: number;
    participation_rate: number | null;
    users_not_picked_this_week: number | null;
  };
  accuracy: {
    avg_accuracy_all_time: number | null;
    avg_accuracy_this_week: number | null;
    upset_rate: number | null;
    most_picked_team: MostPickedTeam | null;
  };
  coverage: {
    total_finished: number;
    total_scheduled: number;
    games_no_picks: number;
    consensus_games: number;
    most_contested: ContestledGame[];
  };
}

export default class StatsTab extends Component {
  private stats: StatsData | null = null;
  private loading: boolean = false;
  private error: Mithril.Children = null;
  private selectedWeekId: string = '';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const weeks = app.store.all<Week>('picks-weeks');
    if (weeks.length > 0) {
      this.initWeek();
      this.load();
    } else {
      app.store.find<Week[]>('picks-weeks').then(() => {
        this.initWeek();
        this.load();
      });
    }
  }

  private initWeek() {
    const sorted = app.store.all<Week>('picks-weeks').sort((a, b) => {
      if (a.seasonType() !== b.seasonType()) return a.seasonType() === 'regular' ? -1 : 1;
      return (a.weekNumber() || 0) - (b.weekNumber() || 0);
    });
    if (sorted.length > 0) this.selectedWeekId = String(sorted[0].id());
  }

  private load() {
    if (!this.selectedWeekId) {
      this.loading = false;
      m.redraw();
      return;
    }

    this.loading = true;
    this.error = null;
    m.redraw();

    const params: Record<string, any> = {};
    if (this.selectedWeekId) params.week_id = this.selectedWeekId;

    app
      .request<StatsData>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/picks/stats',
        params,
      })
      .then((r) => {
        this.stats = r;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.error = t('load_failed');
        this.loading = false;
        m.redraw();
      });
  }

  private sortedWeeks(): Week[] {
    return app.store.all<Week>('picks-weeks').sort((a, b) => {
      if (a.seasonType() !== b.seasonType()) return a.seasonType() === 'regular' ? -1 : 1;
      return (a.weekNumber() || 0) - (b.weekNumber() || 0);
    });
  }

  private statCard(icon: string, labelKey: string, value: string | number | null, suffix: string = ''): Mithril.Children {
    const display = value !== null ? String(value) + suffix : '—';
    const label = t(labelKey);
    return (
      <div className="AnalyticsCard" key={labelKey}>
        <div className="AnalyticsCard-icon">
          <i className={icon} />
        </div>
        <div className="AnalyticsCard-body">
          <div className="AnalyticsCard-value">{display}</div>
          <div className="AnalyticsCard-label">{label}</div>
        </div>
      </div>
    );
  }

  view() {
    const weeks = this.sortedWeeks();
    const s = this.stats;

    return (
      <div className="PicksStatsTab">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-chart-bar" /> {t('title')}
            </h3>
            <p className="PicksTab-meta">{t('intro')}</p>
          </div>
          <div className="PicksTab-actions">
            <select
              className="FormControl"
              value={this.selectedWeekId}
              onchange={(e: Event) => {
                this.selectedWeekId = (e.target as HTMLSelectElement).value;
                this.load();
              }}
            >
              {weeks.map((w) => (
                <option key={String(w.id())} value={String(w.id())}>
                  {w.name()}
                </option>
              ))}
            </select>
            <Button className="Button" icon="fas fa-sync" loading={this.loading} onclick={() => this.load()}>
              {t('refresh')}
            </Button>
          </div>
        </div>

        {this.error && <div className="PicksAlert PicksAlert--error">{this.error}</div>}

        {this.loading ? (
          <LoadingIndicator />
        ) : !this.selectedWeekId ? (
          <div className="PicksEmptyState">{app.translator.trans('ernestdefoe-picks.admin.common.no_schedule')}</div>
        ) : !s ? null : (
          <>
            {/* Participation */}
            <div className="PicksStatsSection">
              <div className="PicksStatsSection-title">
                <i className="fas fa-users" /> {t('participation')}
              </div>
              <div className="PicksStats-cards">
                {this.statCard('fas fa-users', 'total_players', s.participation.total_players)}
                {this.statCard('fas fa-check-circle', 'picked_this_week', s.participation.unique_pickers_this_week)}
                {this.statCard('fas fa-percentage', 'participation_rate', s.participation.participation_rate, '%')}
                {this.statCard('fas fa-user-clock', 'yet_to_pick', s.participation.users_not_picked_this_week)}
              </div>
            </div>

            {/* Accuracy & Scoring */}
            <div className="PicksStatsSection">
              <div className="PicksStatsSection-title">
                <i className="fas fa-bullseye" /> {t('accuracy_title')}
              </div>
              <div className="PicksStats-cards">
                {this.statCard('fas fa-chart-line', 'avg_accuracy_season', s.accuracy.avg_accuracy_all_time, '%')}
                {this.statCard('fas fa-calendar-week', 'avg_accuracy_week', s.accuracy.avg_accuracy_this_week, '%')}
                {this.statCard('fas fa-bolt', 'upset_rate', s.accuracy.upset_rate, '%')}
                {this.statCard('fas fa-football', 'most_picked', s.accuracy.most_picked_team?.abbreviation ?? null)}
              </div>
              {s.accuracy.most_picked_team && (
                <p className="PicksStats-footnote">
                  <i className="fas fa-football" />{' '}
                  {t('most_picked_note', { team: s.accuracy.most_picked_team.name, count: s.accuracy.most_picked_team.picks })}
                </p>
              )}
            </div>

            {/* Game Coverage */}
            <div className="PicksStatsSection">
              <div className="PicksStatsSection-title">
                <i className="fas fa-clipboard-list" /> {t('coverage')}
              </div>
              <div className="PicksStats-cards">
                {this.statCard('fas fa-flag-checkered', 'results_entered', s.coverage.total_finished)}
                {this.statCard('fas fa-clock', 'awaiting_results', s.coverage.total_scheduled)}
                {this.statCard('fas fa-ghost', 'no_picks', s.coverage.games_no_picks)}
                {this.statCard('fas fa-handshake', 'consensus', s.coverage.consensus_games)}
              </div>

              {s.coverage.most_contested.length > 0 && (
                <div className="PicksStats-contestedList">
                  <div className="PicksStats-contestedTitle">{t('most_contested')}</div>
                  {s.coverage.most_contested.map((g) => (
                    <div className="PicksStats-contestedRow" key={String(g.event_id)}>
                      <span className="PicksStats-contestedMatchup">
                        {g.home_team} {app.translator.trans('ernestdefoe-picks.lib.common.vs')} {g.away_team}
                      </span>
                      <div className="PicksStats-contestedBar">
                        <div className="PicksStats-contestedFill PicksStats-contestedFill--home" style={`width: ${g.home_pct}%`} />
                        <div className="PicksStats-contestedFill PicksStats-contestedFill--away" style={`width: ${g.away_pct}%`} />
                      </div>
                      <span className="PicksStats-contestedSplit">
                        {g.home_pct}% / {g.away_pct}%
                      </span>
                      <span className="PicksStats-contestedTotal">{t('picks_count', { count: g.total })}</span>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </>
        )}
      </div>
    );
  }
}
