import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import Team from '../../common/models/Team';
import TeamEditModal from './TeamEditModal';

type LogoStatus = 'both' | 'standard' | 'custom' | 'missing';

export default class TeamsTab extends Component {
  private teams: Team[] = [];
  private loading: boolean = false;
  private syncing: boolean = false;
  private syncingLogos: boolean = false;
  private refreshingId: number | null = null;
  private filterConference: string = 'all';
  private filterLogo: string = 'all';
  private search: string = '';
  private syncResult: string | null = null;
  private logoSyncResult: string | null = null;
  private lastSync: string | null = null;
  private logoProgress: { saved: number; failed: number; remaining: number } | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.lastSync = app.data.settings['ernestdefoe-picks.last_teams_sync'] || null;
    this.loadTeams();
  }

  private loadTeams() {
    this.loading = true;
    m.redraw();

    app.store
      .find<Team[]>('picks-teams', { page: { limit: 500 } })
      .then((teams) => {
        this.teams = teams;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  private syncTeams() {
    this.syncing = true;
    this.syncResult = null;
    m.redraw();

    app
      .request<{ status: string; created: number; updated: number; logos: number; errors: string[]; message?: string }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/picks/sync/teams',
      })
      .then((response) => {
        if (response.status === 'error') {
          this.syncResult = '❌ ' + (response.message || 'Sync failed.');
        } else {
          this.syncResult = `✅ Sync complete. Created: ${response.created}, Updated: ${response.updated}.`;
          this.lastSync = new Date().toISOString();
          this.loadTeams();
        }
        this.syncing = false;
        m.redraw();
      })
      .catch(() => {
        this.syncResult = '❌ Sync request failed. Check API key configuration.';
        this.syncing = false;
        m.redraw();
      });
  }

  private syncLogosBatch() {
    this.syncingLogos = true;
    this.logoSyncResult = null;
    m.redraw();

    const runBatch = () => {
      app
        .request<{ status: string; saved: number; failed: number; remaining: number; message?: string }>({
          method: 'POST',
          url: app.forum.attribute('apiUrl') + '/picks/sync/logos',
          body: { batchSize: 20 },
        })
        .then((response) => {
          if (response.status === 'error') {
            this.logoSyncResult = '❌ ' + (response.message || 'Logo sync failed.');
            this.syncingLogos = false;
            m.redraw();
            return;
          }

          this.logoProgress = {
            saved: (this.logoProgress?.saved || 0) + response.saved,
            failed: (this.logoProgress?.failed || 0) + response.failed,
            remaining: response.remaining,
          };

          m.redraw();

          if (response.remaining > 0) {
            // Continue with next batch after a short pause
            setTimeout(runBatch, 500);
          } else {
            this.logoSyncResult = `✅ Logo sync complete. Saved: ${this.logoProgress?.saved}, Failed: ${this.logoProgress?.failed}.`;
            this.logoProgress = null;
            this.syncingLogos = false;
            this.loadTeams();
            m.redraw();
          }
        })
        .catch(() => {
          this.logoSyncResult = '❌ Logo sync request failed.';
          this.syncingLogos = false;
          m.redraw();
        });
    };

    this.logoProgress = { saved: 0, failed: 0, remaining: 0 };
    runBatch();
  }

  private refreshLogo(team: Team) {
    const id = parseInt(String(team.id()));
    this.refreshingId = id;
    m.redraw();

    app
      .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/picks/teams/${id}/refresh-logo` })
      .then(() => app.store.find<Team>('picks-teams', String(id)))
      .then(() => { this.refreshingId = null; m.redraw(); })
      .catch(() => { this.refreshingId = null; m.redraw(); });
  }

  private logoStatus(team: Team): LogoStatus {
    if (team.logoCustom()) return 'custom';
    if (team.logoPath() && team.logoDarkPath()) return 'both';
    if (team.logoPath()) return 'standard';
    return 'missing';
  }

  private logoStatusLabel(status: LogoStatus): string {
    switch (status) {
      case 'both':     return '✅ Both';
      case 'standard': return '🌓 Standard only';
      case 'custom':   return '🖼 Custom';
      case 'missing':  return '⚠️ Missing';
    }
  }

  private conferences(): string[] {
    const set = new Set<string>();
    this.teams.forEach((t) => { const c = t.conference(); if (c) set.add(c); });
    return Array.from(set).sort();
  }

  private filteredTeams(): Team[] {
    return this.teams.filter((team) => {
      if (this.filterConference !== 'all' && team.conference() !== this.filterConference) return false;
      if (this.filterLogo !== 'all') {
        const status = this.logoStatus(team);
        if (this.filterLogo === 'has' && status === 'missing') return false;
        if (this.filterLogo === 'missing' && status !== 'missing') return false;
        if (this.filterLogo === 'custom' && status !== 'custom') return false;
      }
      if (this.search) {
        const q = this.search.toLowerCase();
        const name = (team.name() || '').toLowerCase();
        const abbr = (team.abbreviation() || '').toLowerCase();
        if (!name.includes(q) && !abbr.includes(q)) return false;
      }
      return true;
    });
  }

  private missingLogoCount(): number {
    return this.teams.filter(t => !t.logoPath() && !t.logoCustom()).length;
  }

  view() {
    const filtered = this.filteredTeams();
    const conferences = this.conferences();
    const missingLogos = this.missingLogoCount();

    return (
      <div className="PicksTeamsTab">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-users" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.nav.teams')}
            </h3>
            <p className="PicksTab-meta">
              {this.teams.length} {app.translator.trans('ernestdefoe-picks.admin.teams.total_label')}
              {this.lastSync && (
                <span>{' · '}{app.translator.trans('ernestdefoe-picks.admin.common.last_sync')}: {new Date(this.lastSync).toLocaleString()}</span>
              )}
            </p>
          </div>
          <div className="PicksTab-actions">
            <Button className="Button Button--primary" icon="fas fa-sync" loading={this.syncing} onclick={() => this.syncTeams()}>
              {app.translator.trans('ernestdefoe-picks.admin.teams.sync_button')}
            </Button>
            {missingLogos > 0 && (
              <Button className="Button" icon="fas fa-images" loading={this.syncingLogos} onclick={() => this.syncLogosBatch()}>
                {app.translator.trans('ernestdefoe-picks.admin.teams.sync_logos_button')} ({missingLogos})
              </Button>
            )}
          </div>
        </div>

        {this.syncResult && <div className="PicksAlert PicksAlert--info">{this.syncResult}</div>}

        {(this.syncingLogos || this.logoSyncResult) && (
          <div className="PicksAlert PicksAlert--info">
            {this.syncingLogos && this.logoProgress !== null ? (
              <span>
                <i className="fas fa-spinner fa-spin" />
                {' '}Downloading logos... Saved: {this.logoProgress.saved}, Remaining: {this.logoProgress.remaining}
              </span>
            ) : (
              this.logoSyncResult
            )}
          </div>
        )}

        <div className="PicksTab-filters">
          <input
            className="FormControl"
            type="text"
            placeholder={app.translator.trans('ernestdefoe-picks.admin.teams.search_placeholder')}
            value={this.search}
            oninput={(e: InputEvent) => { this.search = (e.target as HTMLInputElement).value; }}
          />
          <select className="FormControl" value={this.filterConference} onchange={(e: Event) => { this.filterConference = (e.target as HTMLSelectElement).value; }}>
            <option value="all">{app.translator.trans('ernestdefoe-picks.admin.teams.all_conferences')}</option>
            {conferences.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
          <select className="FormControl" value={this.filterLogo} onchange={(e: Event) => { this.filterLogo = (e.target as HTMLSelectElement).value; }}>
            <option value="all">{app.translator.trans('ernestdefoe-picks.admin.teams.logo_filter_all')}</option>
            <option value="has">{app.translator.trans('ernestdefoe-picks.admin.teams.logo_filter_has')}</option>
            <option value="missing">{app.translator.trans('ernestdefoe-picks.admin.teams.logo_filter_missing')}</option>
            <option value="custom">{app.translator.trans('ernestdefoe-picks.admin.teams.logo_filter_custom')}</option>
          </select>
        </div>

        {this.loading ? (
          <LoadingIndicator />
        ) : (
          <div className="PicksCardList">
            <div className="PicksCardList-header">
              <div>{app.translator.trans('ernestdefoe-picks.admin.teams.col_logo')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.teams.col_name')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.teams.col_conference')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.teams.col_abbrev')}</div>
              <div>{app.translator.trans('ernestdefoe-picks.admin.teams.col_logo_status')}</div>
              <div></div>
            </div>

            {filtered.length === 0 ? (
              <div className="PicksEmptyState">{app.translator.trans('ernestdefoe-picks.admin.teams.no_teams')}</div>
            ) : (
              filtered.map((team) => {
                const id = parseInt(String(team.id()));
                const status = this.logoStatus(team);
                const logoUrl     = team.logoUrl();
                const logoDarkUrl = team.logoDarkUrl() || logoUrl;

                return (
                  <div key={String(team.id())} className="PicksCardList-row">
                    <div className="PicksCardList-cell">
                      {logoUrl ? (
                        <>
                          <img src={logoUrl}     alt={team.name() || ''} className="PicksTeamLogo PicksTeamLogo--light" />
                          <img src={logoDarkUrl} alt={team.name() || ''} className="PicksTeamLogo PicksTeamLogo--dark" />
                        </>
                      ) : (
                        <div className="PicksTeamLogo PicksTeamLogo--placeholder">
                          {(team.abbreviation() || team.name() || '?').charAt(0)}
                        </div>
                      )}
                    </div>
                    <div className="PicksCardList-cell PicksCardList-cell--primary">{team.name()}</div>
                    <div className="PicksCardList-cell">{team.conference() || '—'}</div>
                    <div className="PicksCardList-cell PicksCardList-cell--muted">{team.abbreviation() || '—'}</div>
                    <div className="PicksCardList-cell">{this.logoStatusLabel(status)}</div>
                    <div className="PicksCardList-cell PicksCardList-cell--actions">
                      <Button
                        className="Button Button--icon"
                        icon="fas fa-edit"
                        title={app.translator.trans('ernestdefoe-picks.admin.common.edit')}
                        onclick={() => app.modal.show(TeamEditModal, { team, onsave: () => this.loadTeams() })}
                      />
                      {!team.logoCustom() && team.espnId() && (
                        <Button
                          className="Button Button--icon"
                          icon="fas fa-sync"
                          title={app.translator.trans('ernestdefoe-picks.admin.teams.refresh_logo')}
                          loading={this.refreshingId === id}
                          onclick={() => this.refreshLogo(team)}
                        />
                      )}
                    </div>
                  </div>
                );
              })
            )}
          </div>
        )}
      </div>
    );
  }
}
