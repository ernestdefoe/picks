import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import Week from '../../common/models/Week';
import ResultModal from './ResultModal';

interface GameTeam {
  id: number;
  name: string;
  abbreviation: string | null;
  conference: string | null;
  logo_path: string | null;
  logo_url: string | null;
  logo_dark_url: string | null;
}

interface Game {
  id: number;
  cfbd_id: number | null;
  week_id: number | null;
  week_name: string | null;
  status: string;
  match_date: string | null;
  cutoff_date: string | null;
  neutral_site: boolean;
  home_score: number | null;
  away_score: number | null;
  result: string | null;
  home_team: GameTeam | null;
  away_team: GameTeam | null;
}

interface GamesMeta {
  total: number;
  current_page: number;
  per_page: number;
  last_page: number;
}

export default class GamesTab extends Component {
  private games: Game[] = [];
  private meta: GamesMeta | null = null;
  private loading: boolean = false;
  private page: number = 1;
  private filterWeekId: string = '';
  private filterStatus: string = '';
  private search: string = '';
  private sort: string = 'date_asc';
  private searchTimer: ReturnType<typeof setTimeout> | null = null;
  private syncing: boolean = false;
  private syncResult: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const weeks = app.store.all<Week>('picks-weeks');
    if (weeks.length > 0) {
      this.setDefaultWeek();
      this.load(1);
    } else {
      // Weeks not in store yet — fetch them first
      app.store.find<Week[]>('picks-weeks').then(() => {
        this.setDefaultWeek();
        this.load(1);
      }).catch(() => {
        this.load(1);
      });
    }
  }

  private setDefaultWeek() {
    const weeks = app.store.all<Week>('picks-weeks').sort((a, b) => {
      if (a.seasonType() !== b.seasonType()) return a.seasonType() === 'regular' ? -1 : 1;
      return (a.weekNumber() || 0) - (b.weekNumber() || 0);
    });
    if (weeks.length > 0) {
      this.filterWeekId = String(weeks[0].id());
    }
  }

  private load(page: number) {
    this.loading = true;
    this.page = page;
    m.redraw();

    const params: Record<string, any> = {
      page: this.page,
      per_page: 50,
      sort: this.sort,
    };

    if (this.filterWeekId) params.week_id = this.filterWeekId;
    if (this.filterStatus)  params.status  = this.filterStatus;
    if (this.search)        params.search  = this.search;

    app.request<{ data: Game[]; meta: GamesMeta }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/picks/events',
      params,
    }).then((r) => {
      this.games   = r.data || [];
      this.meta    = r.meta || null;
      this.loading = false;
      m.redraw();
    }).catch(() => {
      this.loading = false;
      m.redraw();
    });
  }

  private onSearchInput(value: string) {
    this.search = value;
    if (this.searchTimer) clearTimeout(this.searchTimer);
    this.searchTimer = setTimeout(() => this.load(1), 300);
  }

  private statusBadge(status: string): Mithril.Children {
    const classes: Record<string, string> = {
      scheduled:   'PicksBadge--scheduled',
      in_progress: 'PicksBadge--in_progress',
      closed:      'PicksBadge--closed',
      finished:    'PicksBadge--finished',
    };
    const labels: Record<string, string> = {
      scheduled:   'Scheduled',
      in_progress: '● Live',
      closed:      'Closed',
      finished:    'Final',
    };
    return (
      <span className={`PicksBadge ${classes[status] || ''}`}>
        {labels[status] || status}
      </span>
    );
  }

  private renderTeamLogo(team: GameTeam | null): Mithril.Children {
    if (!team) return <div className="PicksTeamLogo PicksTeamLogo--placeholder PicksTeamLogo--small">?</div>;

    if (team.logo_url) {
      const darkUrl = team.logo_dark_url || team.logo_url;
      return (
        <>
          <img src={team.logo_url} alt={team.name} className="PicksTeamLogo PicksTeamLogo--small PicksTeamLogo--light" />
          <img src={darkUrl}       alt={team.name} className="PicksTeamLogo PicksTeamLogo--small PicksTeamLogo--dark" />
        </>
      );
    }

    return (
      <div className="PicksTeamLogo PicksTeamLogo--placeholder PicksTeamLogo--small">
        {(team.abbreviation || team.name || '?').charAt(0)}
      </div>
    );
  }

  private formatDate(dateStr: string | null): string {
    if (!dateStr) return '—';
    try {
      return new Date(dateStr).toLocaleDateString(undefined, {
        month: 'short', day: 'numeric', year: 'numeric',
      });
    } catch {
      return dateStr;
    }
  }

  private syncScores() {
    this.syncing = true;
    this.syncResult = null;
    m.redraw();

    app.request<{ status: string; updated: number; scored: number; skipped: number; message?: string }>({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/picks/sync/scores',
    }).then((r) => {
      if (r.status === 'error') {
        this.syncResult = '❌ ' + (r.message || 'Sync failed.');
      } else {
        this.syncResult = `✅ Sync complete. Updated: ${r.updated} games, Scored: ${r.scored} picks batches, Skipped: ${r.skipped}.`;
        this.load(this.page);
      }
      this.syncing = false;
      m.redraw();
    }).catch(() => {
      this.syncResult = '❌ Score sync failed. Check API key and server logs.';
      this.syncing = false;
      m.redraw();
    });
  }

  private sortedWeeks(): Week[] {
    return app.store.all<Week>('picks-weeks').sort((a, b) => {
      if (a.seasonType() !== b.seasonType()) return a.seasonType() === 'regular' ? -1 : 1;
      return (a.weekNumber() || 0) - (b.weekNumber() || 0);
    });
  }

  view() {
    const weeks    = this.sortedWeeks();
    const total     = this.meta?.total ?? 0;
    const lastPage  = this.meta?.last_page ?? 1;

    return (
      <div className="PicksGamesTab">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-football" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.nav.games')}
            </h3>
            <p className="PicksTab-meta">
              {total} {app.translator.trans('ernestdefoe-picks.admin.games.total_label')}
            </p>
          </div>
          <div className="PicksTab-actions">
            <Button
              className="Button Button--primary"
              icon="fas fa-sync"
              loading={this.syncing}
              onclick={() => this.syncScores()}
            >
              {app.translator.trans('ernestdefoe-picks.admin.games.sync_scores_button')}
            </Button>
          </div>
        </div>

        {this.syncResult && (
          <div className="PicksAlert PicksAlert--info">{this.syncResult}</div>
        )}

        <div className="PicksTab-filters">
          <select
            className="FormControl"
            value={this.filterWeekId}
            onchange={(e: Event) => {
              this.filterWeekId = (e.target as HTMLSelectElement).value;
              this.load(1);
            }}
          >
            <option value="">{app.translator.trans('ernestdefoe-picks.admin.games.all_weeks')}</option>
            {weeks.map(w => (
              <option key={String(w.id())} value={String(w.id())}>
                {w.name()}
              </option>
            ))}
          </select>

          <select
            className="FormControl"
            value={this.filterStatus}
            onchange={(e: Event) => {
              this.filterStatus = (e.target as HTMLSelectElement).value;
              this.load(1);
            }}
          >
            <option value="">{app.translator.trans('ernestdefoe-picks.admin.games.all_statuses')}</option>
            <option value="scheduled">{app.translator.trans('ernestdefoe-picks.lib.status.scheduled')}</option>
            <option value="closed">{app.translator.trans('ernestdefoe-picks.lib.status.closed')}</option>
            <option value="finished">{app.translator.trans('ernestdefoe-picks.lib.status.finished')}</option>
          </select>

          <select
            className="FormControl"
            value={this.sort}
            onchange={(e: Event) => {
              this.sort = (e.target as HTMLSelectElement).value;
              this.load(1);
            }}
          >
            <option value="date_asc">{app.translator.trans('ernestdefoe-picks.admin.games.sort_date_asc')}</option>
            <option value="date_desc">{app.translator.trans('ernestdefoe-picks.admin.games.sort_date_desc')}</option>
            <option value="status">{app.translator.trans('ernestdefoe-picks.admin.games.sort_status')}</option>
          </select>

          <input
            className="FormControl"
            type="text"
            placeholder={app.translator.trans('ernestdefoe-picks.admin.games.search_placeholder')}
            value={this.search}
            oninput={(e: InputEvent) => this.onSearchInput((e.target as HTMLInputElement).value)}
          />
        </div>

        {this.loading ? (
          <LoadingIndicator />
        ) : this.games.length === 0 ? (
          <div className="PicksEmptyState">
            {app.translator.trans('ernestdefoe-picks.admin.games.no_games')}
          </div>
        ) : (
          <>
            <div className="PicksCardList">
              <div className="PicksCardList-header PicksCardList-header--games">
                <div>{app.translator.trans('ernestdefoe-picks.admin.games.col_home')}</div>
                <div>{app.translator.trans('ernestdefoe-picks.admin.games.col_away')}</div>
                <div>{app.translator.trans('ernestdefoe-picks.admin.games.col_date')}</div>
                <div>{app.translator.trans('ernestdefoe-picks.admin.games.col_status')}</div>
                <div>{app.translator.trans('ernestdefoe-picks.admin.games.col_score')}</div>
                <div></div>
              </div>

              {this.games.map((game) => (
                <div key={String(game.id)} className="PicksCardList-row PicksCardList-row--games">
                  <div className="PicksCardList-cell PicksTeamCell">
                    {this.renderTeamLogo(game.home_team)}
                    <span>{game.home_team?.name ?? '—'}</span>
                  </div>

                  <div className="PicksCardList-cell PicksTeamCell">
                    {this.renderTeamLogo(game.away_team)}
                    <span>{game.away_team?.name ?? '—'}</span>
                  </div>

                  <div className="PicksCardList-cell PicksCardList-cell--muted">
                    {this.formatDate(game.match_date)}
                  </div>

                  <div className="PicksCardList-cell">
                    {this.statusBadge(game.status)}
                  </div>

                  <div className="PicksCardList-cell">
                    {game.home_score !== null && game.away_score !== null
                      ? `${game.home_score} – ${game.away_score}`
                      : '—'}
                  </div>

                  <div className="PicksCardList-cell PicksCardList-cell--actions">
                    <Button
                      className="Button Button--primary Button--icon"
                      icon="fas fa-check"
                      title={app.translator.trans('ernestdefoe-picks.admin.games.enter_result')}
                      onclick={() =>
                        app.modal.show(ResultModal, {
                          game,
                          onsave: () => this.load(this.page),
                        })
                      }
                    />
                  </div>
                </div>
              ))}
            </div>

            {lastPage > 1 && (
              <div className="PicksPagination">
                <Button
                  className="Button"
                  disabled={this.page <= 1}
                  onclick={() => this.load(this.page - 1)}
                >
                  ← Prev
                </Button>
                <span className="PicksPagination-info">
                  Page {this.page} of {lastPage}
                </span>
                <Button
                  className="Button"
                  disabled={this.page >= lastPage}
                  onclick={() => this.load(this.page + 1)}
                >
                  Next →
                </Button>
              </div>
            )}
          </>
        )}
      </div>
    );
  }
}
