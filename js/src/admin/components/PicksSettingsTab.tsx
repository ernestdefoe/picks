import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import extractText from 'flarum/common/utils/extractText';

export default class PicksSettingsTab extends Component {
  private saving: boolean = false;
  private saveResult: Mithril.Children = null;
  private dirty: boolean = false;

  private picksLockOffsetMinutes: string = '0';
  private espnPollingEnabled: boolean = false;
  private defaultWeekView: string = 'current';
  private confidenceMode: boolean = false;
  private confidencePenalty: string = 'none';
  private navLabel: string = '';
  private autoUnlockWeeks: boolean = false;
  private c10Enabled: boolean = false;
  private c10Games: string = '10';
  private c10Penalty: string = 'none';

  private _orig: Record<string, any> = {};

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const s = app.data.settings;
    this.picksLockOffsetMinutes  = s['ernestdefoe-picks.picks_lock_offset_minutes']  || '0';
    this.espnPollingEnabled      = s['ernestdefoe-picks.espn_polling_enabled'] === '1';
    this.defaultWeekView         = s['ernestdefoe-picks.default_week_view']          || 'current';
    this.confidenceMode          = s['ernestdefoe-picks.confidence_mode'] === '1';
    this.confidencePenalty       = s['ernestdefoe-picks.confidence_penalty']         || 'none';
    this.navLabel                = s['ernestdefoe-picks.nav_label']                  || '';
    this.autoUnlockWeeks         = s['ernestdefoe-picks.auto_unlock_weeks'] === '1';
    this.c10Enabled              = s['ernestdefoe-picks.confidence10_enabled'] === '1';
    this.c10Games                = s['ernestdefoe-picks.confidence10_games']         || '10';
    this.c10Penalty              = s['ernestdefoe-picks.confidence10_penalty']       || 'none';
    this._orig = {
      picksLockOffsetMinutes: this.picksLockOffsetMinutes,
      espnPollingEnabled:     this.espnPollingEnabled,
      defaultWeekView:        this.defaultWeekView,
      confidenceMode:         this.confidenceMode,
      confidencePenalty:      this.confidencePenalty,
      navLabel:               this.navLabel,
      autoUnlockWeeks:        this.autoUnlockWeeks,
      c10Enabled:             this.c10Enabled,
      c10Games:               this.c10Games,
      c10Penalty:             this.c10Penalty,
    };
    this.dirty = false;
  }

  private checkDirty() {
    this.dirty =
      this.picksLockOffsetMinutes !== this._orig.picksLockOffsetMinutes ||
      this.espnPollingEnabled     !== this._orig.espnPollingEnabled      ||
      this.defaultWeekView        !== this._orig.defaultWeekView         ||
      this.confidenceMode         !== this._orig.confidenceMode          ||
      this.confidencePenalty      !== this._orig.confidencePenalty       ||
      this.navLabel               !== this._orig.navLabel                ||
      this.autoUnlockWeeks        !== this._orig.autoUnlockWeeks         ||
      this.c10Enabled             !== this._orig.c10Enabled              ||
      this.c10Games               !== this._orig.c10Games                ||
      this.c10Penalty             !== this._orig.c10Penalty;
  }

  /** 3 to 20; anything else is what the server would clamp it to anyway. */
  private c10GamesClamped(): string {
    const n = parseInt(this.c10Games, 10);
    return String(Math.max(3, Math.min(20, isNaN(n) ? 10 : n)));
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
        'ernestdefoe-picks.picks_lock_offset_minutes': this.picksLockOffsetMinutes,
        'ernestdefoe-picks.espn_polling_enabled':       this.espnPollingEnabled ? '1' : '0',
        'ernestdefoe-picks.default_week_view':          this.defaultWeekView,
        'ernestdefoe-picks.confidence_mode':            this.confidenceMode ? '1' : '0',
        'ernestdefoe-picks.confidence_penalty':         this.confidencePenalty,
        'ernestdefoe-picks.nav_label':                  this.navLabel,
        'ernestdefoe-picks.auto_unlock_weeks':          this.autoUnlockWeeks ? '1' : '0',
        'ernestdefoe-picks.confidence10_enabled':       this.c10Enabled ? '1' : '0',
        'ernestdefoe-picks.confidence10_games':         this.c10GamesClamped(),
        'ernestdefoe-picks.confidence10_penalty':       this.c10Penalty,
      },
    }).then(() => {
      app.data.settings['ernestdefoe-picks.picks_lock_offset_minutes'] = this.picksLockOffsetMinutes;
      app.data.settings['ernestdefoe-picks.espn_polling_enabled']       = this.espnPollingEnabled ? '1' : '0';
      app.data.settings['ernestdefoe-picks.default_week_view']         = this.defaultWeekView;
      app.data.settings['ernestdefoe-picks.confidence_mode']           = this.confidenceMode ? '1' : '0';
      app.data.settings['ernestdefoe-picks.confidence_penalty']        = this.confidencePenalty;
      app.data.settings['ernestdefoe-picks.nav_label']                 = this.navLabel;
      app.data.settings['ernestdefoe-picks.auto_unlock_weeks']         = this.autoUnlockWeeks ? '1' : '0';
      app.data.settings['ernestdefoe-picks.confidence10_enabled']      = this.c10Enabled ? '1' : '0';
      this.c10Games = this.c10GamesClamped();
      app.data.settings['ernestdefoe-picks.confidence10_games']        = this.c10Games;
      app.data.settings['ernestdefoe-picks.confidence10_penalty']      = this.c10Penalty;
      this.saving = false;
      this.dirty = false;
      this._orig = {
        picksLockOffsetMinutes: this.picksLockOffsetMinutes,
        espnPollingEnabled:     this.espnPollingEnabled,
        defaultWeekView:        this.defaultWeekView,
        confidenceMode:         this.confidenceMode,
        confidencePenalty:      this.confidencePenalty,
        navLabel:               this.navLabel,
        autoUnlockWeeks:        this.autoUnlockWeeks,
        c10Enabled:             this.c10Enabled,
        c10Games:               this.c10Games,
        c10Penalty:             this.c10Penalty,
      };
      this.saveResult = app.translator.trans('ernestdefoe-picks.admin.common.saved');
      m.redraw();
    }).catch(() => {
      this.saving = false;
      this.saveResult = app.translator.trans('ernestdefoe-picks.admin.common.save_failed');
      m.redraw();
    });
  }

  view() {
    return (
      <div className="PicksSettingsTab">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-cog" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.nav.settings')}
            </h3>
          </div>
        </div>

        {/* Pick Locking */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.settings.locking_title')}
          </h4>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.settings.lock_offset')}</label>
            <div className="PicksInputRow">
              <input
                className="FormControl PicksInputRow-input"
                type="number"
                min="0"
                max="120"
                value={this.picksLockOffsetMinutes}
                oninput={(e: InputEvent) => { this.picksLockOffsetMinutes = (e.target as HTMLInputElement).value; this.checkDirty(); }}
              />
              <span className="PicksInputRow-label">
                {app.translator.trans('ernestdefoe-picks.admin.settings.minutes_before_kickoff')}
              </span>
            </div>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.lock_offset_help')}
            </p>
          </div>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.autoUnlockWeeks}
                onchange={(e: Event) => { this.autoUnlockWeeks = (e.target as HTMLInputElement).checked; this.checkDirty(); }}
              />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.settings.auto_unlock_weeks')}
            </label>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.auto_unlock_weeks_help')}
            </p>
            {this.autoUnlockWeeks && (
              <p className="helpText PicksHelpNote">
                <i className="fas fa-info-circle" />
                {' '}{app.translator.trans('ernestdefoe-picks.admin.settings.auto_unlock_weeks_note')}
              </p>
            )}
          </div>
        </div>

        {/* Display */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.settings.display_title')}
          </h4>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.settings.nav_label')}</label>
            <input
              className="FormControl"
              type="text"
              value={this.navLabel}
              placeholder={extractText(app.translator.trans('ernestdefoe-picks.lib.nav.picks'))}
              oninput={(e: InputEvent) => { this.navLabel = (e.target as HTMLInputElement).value; this.checkDirty(); }}
            />
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.nav_label_help')}
            </p>
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.settings.default_week_view')}</label>
            <select
              className="FormControl"
              value={this.defaultWeekView}
              onchange={(e: Event) => { this.defaultWeekView = (e.target as HTMLSelectElement).value; this.checkDirty(); }}
            >
              <option value="current">{app.translator.trans('ernestdefoe-picks.admin.settings.week_view_current')}</option>
              <option value="first">{app.translator.trans('ernestdefoe-picks.admin.settings.week_view_first')}</option>
            </select>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.default_week_view_help')}
            </p>
          </div>
        </div>

        {/* Confidence Mode */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.settings.confidence_title')}
          </h4>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.confidenceMode}
                onchange={(e: Event) => { this.confidenceMode = (e.target as HTMLInputElement).checked; this.checkDirty(); }}
              />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.settings.confidence_enabled')}
            </label>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.confidence_help')}
            </p>
          </div>

          {this.confidenceMode && (
            <div className="Form-group">
              <label>{app.translator.trans('ernestdefoe-picks.admin.settings.confidence_penalty')}</label>
              <select
                className="FormControl"
                value={this.confidencePenalty}
                onchange={(e: Event) => { this.confidencePenalty = (e.target as HTMLSelectElement).value; this.checkDirty(); }}
              >
                <option value="none">{app.translator.trans('ernestdefoe-picks.admin.settings.penalty_none')}</option>
                <option value="half">{app.translator.trans('ernestdefoe-picks.admin.settings.penalty_half')}</option>
                <option value="full">{app.translator.trans('ernestdefoe-picks.admin.settings.penalty_full')}</option>
              </select>
              <p className="helpText">
                {this.confidencePenalty === 'none' && app.translator.trans('ernestdefoe-picks.admin.settings.penalty_none_help')}
                {this.confidencePenalty === 'half' && app.translator.trans('ernestdefoe-picks.admin.settings.penalty_half_help')}
                {this.confidencePenalty === 'full' && app.translator.trans('ernestdefoe-picks.admin.settings.penalty_full_help')}
              </p>
            </div>
          )}
        </div>

        {/* Confidence contest — its own game, beside the full board */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.settings.c10_title')}
          </h4>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.c10Enabled}
                onchange={(e: Event) => { this.c10Enabled = (e.target as HTMLInputElement).checked; this.checkDirty(); }}
              />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.settings.c10_enabled')}
            </label>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.c10_help')}
            </p>
          </div>

          {this.c10Enabled && (
            <div className="Form-group">
              <label for="picks-c10-games">{app.translator.trans('ernestdefoe-picks.admin.settings.c10_games')}</label>
              <div className="PicksInputRow">
                <input
                  id="picks-c10-games"
                  className="FormControl PicksInputRow-input"
                  type="number"
                  min="3"
                  max="20"
                  value={this.c10Games}
                  oninput={(e: InputEvent) => { this.c10Games = (e.target as HTMLInputElement).value; this.checkDirty(); }}
                />
                <span className="PicksInputRow-label">
                  {app.translator.trans('ernestdefoe-picks.admin.settings.c10_games_unit')}
                </span>
              </div>
              <p className="helpText">
                {app.translator.trans('ernestdefoe-picks.admin.settings.c10_games_help')}
              </p>
            </div>
          )}

          {this.c10Enabled && (
            <div className="Form-group">
              <label for="picks-c10-penalty">{app.translator.trans('ernestdefoe-picks.admin.settings.c10_penalty')}</label>
              <select
                id="picks-c10-penalty"
                className="FormControl"
                value={this.c10Penalty}
                onchange={(e: Event) => { this.c10Penalty = (e.target as HTMLSelectElement).value; this.checkDirty(); }}
              >
                <option value="none">{app.translator.trans('ernestdefoe-picks.admin.settings.penalty_none')}</option>
                <option value="half">{app.translator.trans('ernestdefoe-picks.admin.settings.penalty_half')}</option>
                <option value="full">{app.translator.trans('ernestdefoe-picks.admin.settings.penalty_full')}</option>
              </select>
              <p className="helpText">
                {this.c10Penalty === 'none' && app.translator.trans('ernestdefoe-picks.admin.settings.penalty_none_help')}
                {this.c10Penalty === 'half' && app.translator.trans('ernestdefoe-picks.admin.settings.penalty_half_help')}
                {this.c10Penalty === 'full' && app.translator.trans('ernestdefoe-picks.admin.settings.penalty_full_help')}
              </p>
            </div>
          )}
        </div>

        {/* ESPN Live Polling */}
        <div className="PicksSettingsSection">
          <h4 className="PicksSettingsSection-title">
            {app.translator.trans('ernestdefoe-picks.admin.settings.polling_title')}
          </h4>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.espnPollingEnabled}
                onchange={(e: Event) => { this.espnPollingEnabled = (e.target as HTMLInputElement).checked; this.checkDirty(); }}
              />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.settings.espn_polling_enabled')}
            </label>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.settings.espn_polling_help')}
            </p>
          </div>

          <div className="Form-group">
            <p className="helpText PicksHelpNote">
              <i className="fas fa-info-circle" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.settings.polling_note')}
            </p>
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
      </div>
    );
  }
}
