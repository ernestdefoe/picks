import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import extractText from 'flarum/common/utils/extractText';

const t = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.admin.sync.' + key, params as any);

export default class SyncSettingsTab extends Component {
  private saving: boolean = false;
  private saveResult: Mithril.Children = null;
  private resetting: string | null = null;
  private resetResult: Mithril.Children = null;
  private dirty: boolean = false;

  // Local copies of settings for editing
  private cfbdApiKey: string = '';
  private seasonYear: string = '';
  private conferenceFilter: string = '';
  private syncRegularSeason: boolean = true;
  private syncPostseason: boolean = true;

  // Saved originals for dirty detection
  private _orig: Record<string, any> = {};

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const s = app.data.settings;
    this.cfbdApiKey        = s['ernestdefoe-picks.cfbd_api_key']        || '';
    this.seasonYear        = s['ernestdefoe-picks.season_year']         || String(new Date().getFullYear());
    this.conferenceFilter  = s['ernestdefoe-picks.conference_filter']   || '';
    this.syncRegularSeason = s['ernestdefoe-picks.sync_regular_season'] !== '0';
    this.syncPostseason    = s['ernestdefoe-picks.sync_postseason']     !== '0';
    this._orig = {
      cfbdApiKey: this.cfbdApiKey,
      seasonYear: this.seasonYear,
      conferenceFilter: this.conferenceFilter,
      syncRegularSeason: this.syncRegularSeason,
      syncPostseason: this.syncPostseason,
    };
    this.dirty = false;
  }

  private checkDirty() {
    this.dirty =
      this.cfbdApiKey        !== this._orig.cfbdApiKey        ||
      this.seasonYear        !== this._orig.seasonYear        ||
      this.conferenceFilter  !== this._orig.conferenceFilter  ||
      this.syncRegularSeason !== this._orig.syncRegularSeason ||
      this.syncPostseason    !== this._orig.syncPostseason;
  }

  private save() {
    if (!this.dirty) return;
    this.saving = true;
    this.saveResult = null;
    m.redraw();

    app.request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/settings',
      body: {
        'ernestdefoe-picks.cfbd_api_key':       this.cfbdApiKey,
        'ernestdefoe-picks.season_year':        this.seasonYear,
        'ernestdefoe-picks.conference_filter':  this.conferenceFilter,
        'ernestdefoe-picks.sync_regular_season': this.syncRegularSeason ? '1' : '0',
        'ernestdefoe-picks.sync_postseason':    this.syncPostseason ? '1' : '0',
      },
    }).then(() => {
      // Update in-memory settings
      app.data.settings['ernestdefoe-picks.cfbd_api_key']        = this.cfbdApiKey;
      app.data.settings['ernestdefoe-picks.season_year']         = this.seasonYear;
      app.data.settings['ernestdefoe-picks.conference_filter']   = this.conferenceFilter;
      app.data.settings['ernestdefoe-picks.sync_regular_season'] = this.syncRegularSeason ? '1' : '0';
      app.data.settings['ernestdefoe-picks.sync_postseason']     = this.syncPostseason ? '1' : '0';
      this.saving = false;
      this.dirty = false;
      this._orig = {
        cfbdApiKey: this.cfbdApiKey,
        seasonYear: this.seasonYear,
        conferenceFilter: this.conferenceFilter,
        syncRegularSeason: this.syncRegularSeason,
        syncPostseason: this.syncPostseason,
      };
      this.saveResult = app.translator.trans('ernestdefoe-picks.admin.common.saved');
      m.redraw();
    }).catch(() => {
      this.saving = false;
      this.saveResult = app.translator.trans('ernestdefoe-picks.admin.common.save_failed');
      m.redraw();
    });
  }

  private reset(scope: 'schedule' | 'all') {
    if (!window.confirm(extractText(t(scope === 'all' ? 'reset_all_confirm' : 'reset_schedule_confirm')))) return;

    // Double-confirm for full reset
    if (scope === 'all') {
      if (!window.confirm(extractText(t('reset_all_confirm_again')))) return;
    }

    this.resetting = scope;
    this.resetResult = null;
    m.redraw();

    app.request<{ status: string; scope: string; counts: Record<string, number>; message?: string }>({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/picks/reset',
      body: { scope },
    }).then((r) => {
      if (r.status === 'error') {
        this.resetResult = r.message || t('reset_failed');
      } else {
        const c = r.counts || {};
        const parts: string[] = [];
        // The response says "events"; members and admins call them games.
        const kinds: Array<[string, string]> = [
          ['seasons', 'seasons'], ['weeks', 'weeks'], ['events', 'games'],
          ['picks', 'picks'], ['scores', 'scores'], ['teams', 'teams'],
        ];
        kinds.forEach(([field, word]) => {
          if (c[field]) parts.push(extractText(t('count_' + word, { count: c[field] })));
        });
        this.resetResult = parts.length ? t('reset_done', { items: parts.join(', ') }) : t('reset_nothing');
      }
      this.resetting = null;
      m.redraw();
    }).catch(() => {
      this.resetResult = t('reset_failed');
      this.resetting = null;
      m.redraw();
    });
  }

  private formatDate(isoString: string | null): string {
    if (!isoString) return extractText(app.translator.trans('ernestdefoe-picks.admin.common.never'));
    try {
      return new Date(isoString).toLocaleString();
    } catch {
      return isoString;
    }
  }

  view() {
    const s = app.data.settings;
    const lastTeams    = s['ernestdefoe-picks.last_teams_sync']    || null;
    const lastSchedule = s['ernestdefoe-picks.last_schedule_sync'] || null;
    const lastScores   = s['ernestdefoe-picks.last_scores_sync']   || null;

    return (
      <div className="PicksSyncSettingsTab">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-sync" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.nav.sync')}
            </h3>
          </div>
        </div>

        {/* Sync Status */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.sync.status_title')}
          </h4>
          <div className="PicksSyncStatus">
            <div className="PicksSyncStatus-row">
              <i className="fas fa-users" />
              <span>{app.translator.trans('ernestdefoe-picks.admin.sync.last_teams')}</span>
              <strong>{this.formatDate(lastTeams)}</strong>
            </div>
            <div className="PicksSyncStatus-row">
              <i className="fas fa-calendar-alt" />
              <span>{app.translator.trans('ernestdefoe-picks.admin.sync.last_schedule')}</span>
              <strong>{this.formatDate(lastSchedule)}</strong>
            </div>
            <div className="PicksSyncStatus-row">
              <i className="fas fa-trophy" />
              <span>{app.translator.trans('ernestdefoe-picks.admin.sync.last_scores')}</span>
              <strong>{this.formatDate(lastScores)}</strong>
            </div>
          </div>
        </div>

        {/* API Configuration */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.sync.api_title')}
          </h4>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.sync.cfbd_api_key')}</label>
            <input
              className="FormControl"
              type="password"
              value={this.cfbdApiKey}
              placeholder={extractText(t('cfbd_api_key_placeholder'))}
              oninput={(e: InputEvent) => { this.cfbdApiKey = (e.target as HTMLInputElement).value; this.checkDirty(); }}
            />
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.sync.cfbd_api_key_help')}
            </p>
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.sync.season_year')}</label>
            <input
              className="FormControl"
              type="number"
              value={this.seasonYear}
              min="2000"
              max="2099"
              oninput={(e: InputEvent) => { this.seasonYear = (e.target as HTMLInputElement).value; this.checkDirty(); }}
            />
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.sync.season_year_help')}
            </p>
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.sync.conference_filter')}</label>
            <input
              className="FormControl"
              type="text"
              value={this.conferenceFilter}
              placeholder={extractText(t('conference_filter_placeholder'))}
              oninput={(e: InputEvent) => { this.conferenceFilter = (e.target as HTMLInputElement).value; this.checkDirty(); }}
            />
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.sync.conference_filter_help')}
            </p>
          </div>
        </div>

        {/* Sync Options */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.sync.options_title')}
          </h4>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.syncRegularSeason}
                onchange={(e: Event) => { this.syncRegularSeason = (e.target as HTMLInputElement).checked; this.checkDirty(); }}
              />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.sync.sync_regular_season')}
            </label>
          </div>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.syncPostseason}
                onchange={(e: Event) => { this.syncPostseason = (e.target as HTMLInputElement).checked; this.checkDirty(); }}
              />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.sync.sync_postseason')}
            </label>
          </div>
        </div>

        {this.saveResult && (
          <div className="PicksAlert PicksAlert--info">{this.saveResult}</div>
        )}

        <div className="Form-group">
          <Button
            className="Button Button--primary"
            loading={this.saving}
            disabled={!this.dirty}
            onclick={() => this.save()}
          >
            {app.translator.trans('ernestdefoe-picks.admin.common.save')}
          </Button>
        </div>

        {/* Danger Zone */}
        <div className="PicksDangerZone">
          <h4 className="PicksDangerZone-title">
            <i className="fas fa-exclamation-triangle" />
            {' '}{app.translator.trans('ernestdefoe-picks.admin.sync.danger_title')}
          </h4>
          <p className="PicksDangerZone-description">
            {app.translator.trans('ernestdefoe-picks.admin.sync.danger_description')}
          </p>

          <div className="PicksDangerZone-actions">
            <div className="PicksDangerZone-action">
              <div>
                <strong>{app.translator.trans('ernestdefoe-picks.admin.sync.reset_schedule_title')}</strong>
                <p>{app.translator.trans('ernestdefoe-picks.admin.sync.reset_schedule_description')}</p>
              </div>
              <Button
                className="Button Button--danger"
                loading={this.resetting === 'schedule'}
                disabled={this.resetting !== null}
                onclick={() => this.reset('schedule')}
              >
                {app.translator.trans('ernestdefoe-picks.admin.sync.reset_schedule_button')}
              </Button>
            </div>

            <div className="PicksDangerZone-action PicksDangerZone-action--severe">
              <div>
                <strong>{app.translator.trans('ernestdefoe-picks.admin.sync.reset_all_title')}</strong>
                <p>{app.translator.trans('ernestdefoe-picks.admin.sync.reset_all_description')}</p>
              </div>
              <Button
                className="Button Button--danger"
                loading={this.resetting === 'all'}
                disabled={this.resetting !== null}
                onclick={() => this.reset('all')}
              >
                {app.translator.trans('ernestdefoe-picks.admin.sync.reset_all_button')}
              </Button>
            </div>
          </div>

          {this.resetResult && (
            <div className="PicksAlert PicksAlert--info" style="margin-top: 12px;">
              {this.resetResult}
            </div>
          )}
        </div>
      </div>
    );
  }
}
