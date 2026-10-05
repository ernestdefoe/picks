import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import Season from '../../common/models/Season';
import Week from '../../common/models/Week';
import LeaguesPanel from './LeaguesPanel';
import extractText from 'flarum/common/utils/extractText';

const t = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.admin.seasons.' + key, params as any);

export default class SeasonsTab extends Component {
  private seasons: Season[] = [];
  private weeks: Week[] = [];
  private loading: boolean = false;
  private syncing: boolean = false;
  private selectedSeasonId: string | null = null;
  private syncResult: Mithril.Children = null;
  private lastSync: string | null = null;
  private editingWeekId: string | null = null;
  private editingWeekName: string = '';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.lastSync = app.data.settings['ernestdefoe-picks.last_schedule_sync'] || null;
    this.loadSeasons();
  }

  private loadSeasons() {
    this.loading = true;
    m.redraw();

    // Load seasons and all weeks together, then filter client-side.
    // Flarum 2.x does not support filter[] params on resource endpoints
    // without a registered model searcher — we avoid that by filtering in JS.
    Promise.all([
      app.store.find<Season[]>('picks-seasons'),
      app.store.find<Week[]>('picks-weeks'),
    ]).then(([seasons, _weeks]) => {
        this.seasons = seasons.sort((a, b) => (b.year() || 0) - (a.year() || 0));
        if (this.seasons.length > 0 && ! this.selectedSeasonId) {
          this.selectedSeasonId = String(this.seasons[0].id());
        }
        this.filterAndSortWeeks();
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  private filterAndSortWeeks() {
    const allWeeks = app.store.all<Week>('picks-weeks');
    this.weeks = allWeeks
      .filter(w => String(w.seasonId()) === this.selectedSeasonId)
      .sort((a, b) => {
        // Regular season before postseason
        if (a.seasonType() !== b.seasonType()) {
          return a.seasonType() === 'regular' ? -1 : 1;
        }
        return (a.weekNumber() || 0) - (b.weekNumber() || 0);
      });
  }

  private syncSchedule() {
    this.syncing = true;
    this.syncResult = null;
    m.redraw();

    app
      .request<{
        status: string;
        weeksCreated: number;
        weeksUpdated: number;
        gamesCreated: number;
        gamesUpdated: number;
        message?: string;
      }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/picks/sync/schedule',
      })
      .then((response) => {
        if (response.status === 'error') {
          this.syncResult = response.message || t('sync_failed');
        } else {
          this.syncResult = t('sync_done', {
            weeksCreated: response.weeksCreated,
            weeksUpdated: response.weeksUpdated,
            gamesCreated: response.gamesCreated,
            gamesUpdated: response.gamesUpdated,
          });
          this.lastSync = new Date().toISOString();
          // Clear store cache and reload
          app.store.models['picks-seasons'] = {};
          app.store.models['picks-weeks'] = {};
          this.loadSeasons();
        }
        this.syncing = false;
        m.redraw();
      })
      .catch(() => {
        this.syncResult = t('sync_failed');
        this.syncing = false;
        m.redraw();
      });
  }

  private togglingWeekId: string | null = null;

  private toggleWeekOpen(week: Week) {
    const newState = !week.isOpen();
    this.togglingWeekId = String(week.id());
    m.redraw();

    app.request<{ status: string; week_id: number; is_open: boolean }>({
      method: 'POST',
      url: `${app.forum.attribute('apiUrl')}/picks/weeks/${week.id()}/open`,
      body: { is_open: newState },
    }).then((r) => {
      // Update the store model directly
      (week as any).data.attributes.isOpen = r.is_open;
      this.togglingWeekId = null;
      m.redraw();
    }).catch(() => {
      this.togglingWeekId = null;
      m.redraw();
    });
  }

  private saveWeekName(week: Week) {
    week.save({ name: this.editingWeekName }).then(() => {
      this.editingWeekId = null;
      m.redraw();
    });
  }

  view() {
    return (
      <div className="PicksSeasonsTab">
        {/*
          Which competitions the board follows, above the weeks that belong to
          one of them. A week list is meaningless until a season exists to hold
          it, and until this panel there was no way to make one.
        */}
        <LeaguesPanel
          seasons={this.seasons}
          loading={this.loading}
          onchange={() => {
            app.store.models['picks-seasons'] = {};
            app.store.models['picks-weeks'] = {};
            this.loadSeasons();
          }}
        />

        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-calendar-alt" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.nav.seasons')}
            </h3>
            <p className="PicksTab-meta">
              {this.seasons.length} {app.translator.trans('ernestdefoe-picks.admin.seasons.seasons_label')}
              {this.lastSync && (
                <span>{' · '}{app.translator.trans('ernestdefoe-picks.admin.common.last_sync')}: {new Date(this.lastSync).toLocaleString()}</span>
              )}
            </p>
          </div>
          <div className="PicksTab-actions">
            <Button
              className="Button Button--primary"
              icon="fas fa-sync"
              loading={this.syncing}
              onclick={() => this.syncSchedule()}
            >
              {app.translator.trans('ernestdefoe-picks.admin.seasons.sync_button')}
            </Button>
          </div>
        </div>

        {this.syncResult && (
          <div className="PicksAlert PicksAlert--info">{this.syncResult}</div>
        )}

        {this.seasons.length > 1 && (
          <div className="PicksTab-filters">
            <select
              className="FormControl"
              value={this.selectedSeasonId || ''}
              onchange={(e: Event) => {
                this.selectedSeasonId = (e.target as HTMLSelectElement).value;
                this.filterAndSortWeeks();
                m.redraw();
              }}
            >
              {this.seasons.map(s => (
                <option key={String(s.id())} value={String(s.id())}>
                  {s.name()}
                </option>
              ))}
            </select>
          </div>
        )}

        {this.loading ? (
          <LoadingIndicator />
        ) : this.weeks.length === 0 ? (
          <div className="PicksEmptyState">
            {app.translator.trans('ernestdefoe-picks.admin.seasons.no_weeks')}
          </div>
        ) : (
          <div className="PicksCardList">
            <div className="PicksCardList-header PicksCardList-header--seasons">
              <div>{app.translator.trans('ernestdefoe-picks.admin.seasons.col_week')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.seasons.col_type')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.seasons.col_dates')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.seasons.col_name')}</div>
              <div></div>
            </div>

            {this.weeks.map((week) => {
              const isEditing = this.editingWeekId === String(week.id());

              return (
                <div key={String(week.id())} className="PicksCardList-row PicksCardList-row--seasons">
                  <div className="PicksCardList-cell PicksCardList-cell--primary">
                    {week.seasonType() === 'postseason' ? t('postseason_short') : t('week_short', { number: week.weekNumber() })}
                  </div>

                  <div className="PicksCardList-cell">
                    <span className={`PicksBadge PicksBadge--${week.seasonType()}`}>
                      {week.seasonType() === 'postseason' ? t('type_postseason') : t('type_regular')}
                    </span>
                  </div>

                  <div className="PicksCardList-cell PicksCardList-cell--muted">
                    {week.startDate() && week.endDate()
                      ? `${week.startDate()} – ${week.endDate()}`
                      : week.startDate() || '—'}
                  </div>

                  <div className="PicksCardList-cell">
                    {isEditing ? (
                      <input
                        className="FormControl FormControl--small"
                        type="text"
                        value={this.editingWeekName}
                        oninput={(e: InputEvent) => {
                          this.editingWeekName = (e.target as HTMLInputElement).value;
                        }}
                        onkeydown={(e: KeyboardEvent) => {
                          if (e.key === 'Enter') this.saveWeekName(week);
                          if (e.key === 'Escape') { this.editingWeekId = null; m.redraw(); }
                        }}
                      />
                    ) : (
                      week.name()
                    )}
                  </div>

                  <div className="PicksCardList-cell PicksCardList-cell--actions">
                    {/* Open/Close toggle */}
                    <Button
                      className={`Button Button--icon ${week.isOpen() ? 'Button--primary' : ''}`}
                      icon={week.isOpen() ? 'fas fa-lock-open' : 'fas fa-lock'}
                      title={extractText(week.isOpen() ? t('close_week') : t('open_week'))}
                      aria-label={extractText(week.isOpen() ? t('close_week') : t('open_week'))}
                      loading={this.togglingWeekId === String(week.id())}
                      onclick={() => this.toggleWeekOpen(week)}
                    />

                    {isEditing ? (
                      <>
                        <Button
                          className="Button Button--primary Button--icon"
                          icon="fas fa-check"
                          aria-label={extractText(t('save_name'))}
                          onclick={() => this.saveWeekName(week)}
                        />
                        <Button
                          className="Button Button--icon"
                          icon="fas fa-times"
                          aria-label={extractText(t('cancel_edit'))}
                          onclick={() => { this.editingWeekId = null; m.redraw(); }}
                        />
                      </>
                    ) : (
                      <Button
                        className="Button Button--icon"
                        icon="fas fa-edit"
                        title={app.translator.trans('ernestdefoe-picks.admin.common.edit')}
                        onclick={() => {
                          this.editingWeekId = String(week.id());
                          this.editingWeekName = week.name() || '';
                          m.redraw();
                        }}
                      />
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    );
  }
}
