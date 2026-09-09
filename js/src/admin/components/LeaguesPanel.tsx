import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import Season from '../../common/models/Season';

declare const m: any;

const t = (k: string, p?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.admin.leagues.' + k, p);

interface LeagueDef {
  name: string;
  provider: string;
  sport: string;
}

interface Attrs {
  seasons: Season[];
  /**
   * 🚨 Told apart from an empty list, deliberately.
   *
   * Seasons arrive after the first render, so without this the panel opens on
   * "No seasons yet. Create one" every single time — and on a slow request, or
   * a failed one, that sentence is the only thing anybody ever sees. An empty
   * state that also means "still loading" is a wrong answer that looks like a
   * right one.
   */
  loading: boolean;
  /** Ask the tab to reload — a new season changes the week list under it. */
  onchange: () => void;
}

/**
 * Which competitions this board follows.
 *
 * 🚨 The only place the league is set, and the reason this exists. A season's
 * league decides which provider fills its fixtures, which words a Game Day
 * recap is written in, and which scoring rules a Fantasy league starts from —
 * three extensions reading one column that no screen could write. Multi-sport
 * was reachable by editing the database and by nothing else.
 */
export default class LeaguesPanel extends Component<Attrs> {
  private adding = false;
  private draft = { name: '', year: new Date().getFullYear(), league: 'cfb' };
  private busyId: string | null = null;
  private saving = false;
  private message: string | null = null;
  private error = false;

  /**
   * 🚨 From the SERVER's registry, never a list written here. A copy in the
   * bundle goes stale the first time an extension registers a competition.
   */
  private registry(): Record<string, LeagueDef> {
    return ((app.data as any)?.picksLeagues ?? {}) as Record<string, LeagueDef>;
  }

  oninit(vnode: Mithril.Vnode<Attrs>) {
    super.oninit(vnode);

    const keys = Object.keys(this.registry());
    if (keys.length && !keys.includes(this.draft.league)) this.draft.league = keys[0];
  }

  view() {
    const registry = this.registry();
    const seasons = this.attrs.seasons;

    return (
      <div className="PicksLeagues">
        <div className="PicksTab-header">
          <div>
            <h3><i className="fas fa-trophy" /> {t('title')}</h3>
            <p className="PicksTab-meta">{t('description')}</p>
          </div>
          <div className="PicksTab-actions">
            <Button
              className="Button"
              icon={this.adding ? 'fas fa-times' : 'fas fa-plus'}
              onclick={() => { this.adding = !this.adding; this.message = null; m.redraw(); }}
            >
              {this.adding ? t('cancel') : t('add')}
            </Button>
          </div>
        </div>

        {this.message ? (
          <div className={`PicksAlert PicksAlert--${this.error ? 'error' : 'info'}`}>{this.message}</div>
        ) : null}

        {/* An empty registry means the payload never arrived — said out loud,
            because an empty dropdown is indistinguishable from "no leagues". */}
        {Object.keys(registry).length === 0 ? (
          <div className="PicksAlert PicksAlert--error">{t('registry_missing')}</div>
        ) : null}

        {this.adding ? this.form(registry) : null}

        {this.attrs.loading ? (
          <LoadingIndicator />
        ) : seasons.length === 0 ? (
          <div className="PicksEmptyState">{t('no_seasons')}</div>
        ) : (
          <div className="PicksCardList">
            <div className="PicksCardList-header PicksCardList-header--leagues">
              <div>{t('col_season')}</div>
              <div>{t('col_league')}</div>
              <div>{t('col_sport')}</div>
              <div />
            </div>

            {seasons.map((season) => this.row(season, registry))}
          </div>
        )}
      </div>
    );
  }

  private row(season: Season, registry: Record<string, LeagueDef>) {
    const key = season.league() || 'cfb';
    const def = registry[key];
    const busy = this.busyId === String(season.id());

    return (
      <div key={String(season.id())} className="PicksCardList-row PicksCardList-row--leagues">
        <div className="PicksCardList-cell PicksCardList-cell--primary">{season.name()}</div>

        <div className="PicksCardList-cell">
          <select
            className="FormControl FormControl--small"
            value={key}
            disabled={busy}
            onchange={(e: Event) => this.setLeague(season, (e.target as HTMLSelectElement).value)}
          >
            {Object.keys(registry).map((k) => (
              <option key={k} value={k}>{registry[k].name}</option>
            ))}
          </select>
        </div>

        <div className="PicksCardList-cell PicksCardList-cell--muted">
          {/* The server's own word for it, so the sport a season is scored and
              written in is visible without opening anything else. */}
          {season.sport() || def?.sport || '—'}
        </div>

        <div className="PicksCardList-cell PicksCardList-cell--actions">
          {/*
           * 🚨 Only where ESPN is the provider. College football comes from
           * CollegeFootballData and has a history ESPN's scoreboard does not
           * carry, so the button that would overwrite it is not offered — the
           * server refuses it as well, because a control that is merely hidden
           * is still a request somebody can make.
           */}
          {def?.provider === 'espn' ? (
            <Button
              className="Button Button--icon"
              icon="fas fa-satellite-dish"
              title={t('sync_espn')}
              loading={busy}
              onclick={() => this.syncEspn(season)}
            />
          ) : (
            <span className="PicksBadge">{t('provider_cfbd')}</span>
          )}
        </div>
      </div>
    );
  }

  private form(registry: Record<string, LeagueDef>) {
    return (
      <div className="PicksLeagues-form">
        <div className="Form-group">
          <label>{t('field_name')}</label>
          <input
            className="FormControl"
            type="text"
            placeholder={t('name_placeholder') as unknown as string}
            value={this.draft.name}
            oninput={(e: InputEvent) => { this.draft.name = (e.target as HTMLInputElement).value; }}
          />
        </div>

        <div className="Form-group">
          <label>{t('field_year')}</label>
          <input
            className="FormControl"
            type="number"
            value={this.draft.year}
            oninput={(e: InputEvent) => { this.draft.year = parseInt((e.target as HTMLInputElement).value, 10) || 0; }}
          />
        </div>

        <div className="Form-group">
          <label>{t('field_league')}</label>
          <select
            className="FormControl"
            value={this.draft.league}
            onchange={(e: Event) => { this.draft.league = (e.target as HTMLSelectElement).value; }}
          >
            {Object.keys(registry).map((k) => (
              <option key={k} value={k}>{registry[k].name}</option>
            ))}
          </select>
        </div>

        <Button
          className="Button Button--primary"
          loading={this.saving}
          disabled={!this.draft.name.trim() || !this.draft.year}
          onclick={() => this.create()}
        >
          {t('create')}
        </Button>
      </div>
    );
  }

  private setLeague(season: Season, league: string) {
    this.busyId = String(season.id());
    this.message = null;
    m.redraw();

    season
      .save({ league })
      .then(() => { this.busyId = null; this.say(t('league_saved', { season: season.name() }) as unknown as string, false); })
      .catch(() => { this.busyId = null; this.say(t('save_failed') as unknown as string, true); });
  }

  private create() {
    this.saving = true;
    this.message = null;
    m.redraw();

    const name = this.draft.name.trim();

    app.store
      .createRecord<Season>('picks-seasons')
      .save({
        name,
        // Derived rather than asked for: a slug field on a form is one more
        // thing to get wrong, and nothing here needs it to be chosen.
        slug: name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''),
        year: this.draft.year,
        league: this.draft.league,
      })
      .then(() => {
        this.saving = false;
        this.adding = false;
        this.draft = { name: '', year: new Date().getFullYear(), league: this.draft.league };
        this.say(t('season_created') as unknown as string, false);
        this.attrs.onchange();
      })
      .catch(() => {
        this.saving = false;
        this.say(t('create_failed') as unknown as string, true);
      });
  }

  private syncEspn(season: Season) {
    this.busyId = String(season.id());
    this.message = null;
    m.redraw();

    app
      .request<{ status: string; message?: string; created?: number; updated?: number; teams?: number }>({
        method: 'POST',
        url: app.forum.attribute('apiUrl') + '/picks/sync/espn',
        body: { season_id: Number(season.id()) },
      })
      .then((res) => {
        this.busyId = null;

        if (res.status !== 'ok') {
          this.say(res.message || (t('sync_failed') as unknown as string), true);
          return;
        }

        this.say(
          t('sync_done', {
            created: res.created ?? 0,
            updated: res.updated ?? 0,
            teams: res.teams ?? 0,
          }) as unknown as string,
          false
        );
        this.attrs.onchange();
      })
      .catch((e: any) => {
        this.busyId = null;
        // The server's own words where it gave any — "not synced from ESPN" is
        // a far better message than "sync failed".
        this.say(e?.response?.message || (t('sync_failed') as unknown as string), true);
      });
  }

  private say(message: string, error: boolean) {
    this.message = message;
    this.error = error;
    m.redraw();
  }
}
