import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import Week from '../../common/models/Week';
import { startDrag, moveItem } from '../../common/sortable';
import crestUrl from '../../common/crest';

interface Team {
  name: string;
  abbreviation: string | null;
  logo_url: string | null;
}

interface Game {
  id: number;
  status: string;
  pickable: boolean;
  match_date: string | null;
  time_tbd: boolean;
  home_rank: number | null;
  away_rank: number | null;
  home_record: string | null;
  away_record: string | null;
  broadcast: string | null;
  home_team: Team | null;
  away_team: Team | null;
}

interface View {
  week_id: number;
  week_name: string;
  week_open: boolean;
  enabled: boolean;
  size: number;
  frozen: boolean;
  selection: Game[];
  candidates: Game[];
}

const t = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.admin.confidence.' + key, params as any);

/**
 * A week's Confidence games, for the admin: the automatic choice, and the
 * means to change it.
 *
 * Every change saves at once. Drag the chosen games to reorder them — the first
 * is the tiebreaker — take one out with its remove button, and add another
 * from the rest of the week, biggest matchups first. Once the first chosen game
 * locks the whole set is frozen, because members are already playing it.
 */
export default class ConfidenceTab extends Component {
  private weekId = '';
  private view_: View | null = null;
  private loading = false;
  private busy = false;
  private message: Mithril.Children = null;
  private filter = '';

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const pick = () => {
      const weeks = this.weeks();
      const current = weeks.find((w) => (w as any).isCurrent?.()) || weeks.find((w) => w.isOpen?.()) || weeks[0];
      if (current) {
        this.weekId = String(current.id());
        this.load();
      }
      m.redraw();
    };

    if (app.store.all<Week>('picks-weeks').length) pick();
    else app.store.find<Week[]>('picks-weeks').then(pick).catch(() => {});
  }

  private weeks(): Week[] {
    return app.store.all<Week>('picks-weeks').sort((a, b) => {
      if (a.seasonType() !== b.seasonType()) return a.seasonType() === 'regular' ? -1 : 1;
      return (a.weekNumber() || 0) - (b.weekNumber() || 0);
    });
  }

  private url(suffix = ''): string {
    return `${app.forum.attribute('apiUrl')}/picks/confidence/weeks/${this.weekId}${suffix}`;
  }

  private load(): void {
    if (!this.weekId) return;
    this.loading = true;
    this.message = null;
    m.redraw();

    app
      .request<{ data: View }>({ method: 'GET', url: this.url() })
      .then((r) => {
        this.view_ = r.data;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  private send(body: Record<string, unknown> | null, suffix = '', done?: Mithril.Children): void {
    this.busy = true;
    this.message = null;
    m.redraw();

    app
      .request<{ data: View }>({ method: 'POST', url: this.url(suffix), body: body || {}, errorHandler: () => {} })
      .then((r) => {
        this.view_ = r.data;
        this.busy = false;
        this.message = done ?? null;
        m.redraw();
      })
      .catch((e: any) => {
        this.busy = false;
        if (e?.response?.data) this.view_ = e.response.data;
        const code = e?.response?.code;
        this.message = (
          <span className="PicksC10Admin-error">
            {t('errors.' + (['frozen', 'too_many', 'started', 'no_games', 'not_in_week'].includes(code) ? code : 'generic'), {
              count: this.view_?.size,
            })}
          </span>
        );
        m.redraw();
      });
  }

  private save(ids: number[]): void {
    this.send({ event_ids: ids });
  }

  private ids(): number[] {
    return (this.view_?.selection || []).map((g) => g.id);
  }

  view() {
    const v = this.view_;

    return (
      <div className="PicksC10Admin">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-sort-amount-down" /> {t('title', { count: v?.size ?? app.data.settings['ernestdefoe-picks.confidence10_games'] ?? 10 })}
            </h3>
            <p className="PicksTab-meta">{t('intro')}</p>
          </div>
          <div className="PicksTab-actions">
            <select
              className="FormControl"
              aria-label={extractText(t('week_label'))}
              value={this.weekId}
              onchange={(e: Event) => {
                this.weekId = (e.target as HTMLSelectElement).value;
                this.load();
              }}
            >
              {this.weeks().map((w) => (
                <option value={String(w.id())}>{w.name()}</option>
              ))}
            </select>
          </div>
        </div>

        {v && !v.enabled && <div className="PicksAlert PicksAlert--info">{t('disabled')}</div>}

        {this.loading || !v ? <LoadingIndicator /> : this.body(v)}
      </div>
    );
  }

  private body(v: View): Mithril.Children {
    const full = v.selection.length >= v.size;
    const needle = this.filter.trim().toLowerCase();
    const candidates = v.candidates.filter(
      (g) => !needle || `${g.home_team?.name ?? ''} ${g.away_team?.name ?? ''}`.toLowerCase().includes(needle)
    );

    return (
      <div>
        <div className="PicksC10Admin-bar">
          <span className={`PicksBadge ${v.frozen ? 'PicksBadge--closed' : 'PicksBadge--scheduled'}`}>
            {v.frozen ? t('frozen_badge') : t('open_badge')}
          </span>
          <span className="PicksC10Admin-count">{t('count', { chosen: v.selection.length, count: v.size })}</span>
          {!v.week_open && <span className="PicksC10Admin-note">{t('week_not_open')}</span>}
          <Button
            className="Button"
            icon="fas fa-wand-magic-sparkles"
            loading={this.busy}
            disabled={v.frozen}
            onclick={() => {
              if (v.selection.length && !confirm(extractText(t('auto_confirm')))) return;
              this.send(null, '/auto', t('auto_done'));
            }}
          >
            {v.selection.length ? t('auto_again') : t('auto')}
          </Button>
        </div>

        {v.frozen && <p className="helpText">{t('frozen_help')}</p>}
        {this.message && <div className="PicksAlert PicksAlert--info">{this.message}</div>}

        <h4 className="PicksC10Admin-heading">{t('chosen_heading')}</h4>
        <p className="helpText">{t('chosen_help')}</p>

        {v.selection.length === 0 ? (
          <div className="PicksEmptyState">{t('none_chosen')}</div>
        ) : (
          <ol className="PicksC10Admin-list">
            {v.selection.map((g, i) => (
              <li className="PicksC10Admin-row" key={String(g.id)}>
                {v.frozen ? (
                  <span className="PicksC10Admin-handle PicksC10Admin-handle--static" aria-hidden="true">
                    <i className="fas fa-lock" />
                  </span>
                ) : (
                  <button
                    type="button"
                    className="PicksC10Admin-handle"
                    aria-label={extractText(t('handle_label', { game: this.matchup(g) }))}
                    onpointerdown={(e: PointerEvent) =>
                      startDrag(e, {
                        list: (e.currentTarget as HTMLElement).closest('.PicksC10Admin-list') as HTMLElement,
                        rowSelector: '.PicksC10Admin-row',
                        from: i,
                        onDrop: (from, to) => this.save(moveItem(this.ids(), from, to)),
                      })
                    }
                    onkeydown={(e: KeyboardEvent) => {
                      if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
                      e.preventDefault();
                      const to = i + (e.key === 'ArrowUp' ? -1 : 1);
                      if (to >= 0 && to < v.selection.length) this.save(moveItem(this.ids(), i, to));
                    }}
                  >
                    <i className="fas fa-grip-vertical" aria-hidden="true" />
                  </button>
                )}
                <span className="PicksC10Admin-pos">{i + 1}</span>
                {this.game(g)}
                {i === 0 && <span className="PicksC10Admin-tb">{t('tiebreaker')}</span>}
                {!v.frozen && (
                  <Button
                    className="Button Button--icon PicksC10Admin-remove"
                    icon="fas fa-times"
                    aria-label={extractText(t('remove_label', { game: this.matchup(g) }))}
                    title={extractText(t('remove'))}
                    disabled={this.busy}
                    onclick={() => this.save(this.ids().filter((id) => id !== g.id))}
                  />
                )}
              </li>
            ))}
          </ol>
        )}

        {!v.frozen && (
          <div className="PicksC10Admin-add">
            <h4 className="PicksC10Admin-heading">{t('add_heading')}</h4>
            <p className="helpText">{full ? t('full_help', { count: v.size }) : t('add_help')}</p>
            <input
              className="FormControl PicksC10Admin-filter"
              type="search"
              placeholder={extractText(t('filter_placeholder'))}
              value={this.filter}
              oninput={(e: InputEvent) => (this.filter = (e.target as HTMLInputElement).value)}
            />
            <ul className="PicksC10Admin-list PicksC10Admin-list--candidates">
              {candidates.map((g) => (
                <li className={`PicksC10Admin-row ${g.pickable ? '' : 'PicksC10Admin-row--started'}`} key={String(g.id)}>
                  {this.game(g)}
                  {g.pickable ? (
                    <Button
                      className="Button PicksC10Admin-addBtn"
                      icon="fas fa-plus"
                      disabled={full || this.busy}
                      aria-label={extractText(t('add_label', { game: this.matchup(g) }))}
                      onclick={() => this.save([...this.ids(), g.id])}
                    >
                      {t('add')}
                    </Button>
                  ) : (
                    <span className="PicksC10Admin-note">{t('started')}</span>
                  )}
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    );
  }

  private game(g: Game): Mithril.Children {
    const side = (team: Team | null, rank: number | null, record: string | null) => (
      <span className="PicksC10Admin-team">
        {team?.logo_url ? <img src={crestUrl(team.logo_url, 24)} alt="" className="PicksTeamLogo PicksTeamLogo--small" loading="lazy" decoding="async" /> : null}
        {rank ? <strong className="PicksC10Admin-rank">#{rank}</strong> : null}
        <span>{team?.name ?? '—'}</span>
        {record ? <span className="PicksC10Admin-record">({record})</span> : null}
      </span>
    );

    return (
      <span className="PicksC10Admin-game">
        <span className="PicksC10Admin-teams">
          {side(g.home_team, g.home_rank, g.home_record)}
          <span className="PicksC10Admin-vs">{app.translator.trans('ernestdefoe-picks.lib.common.vs')}</span>
          {side(g.away_team, g.away_rank, g.away_record)}
        </span>
        <span className="PicksC10Admin-when">
          {this.when(g)}
          {g.broadcast ? ` · ${g.broadcast}` : ''}
        </span>
      </span>
    );
  }

  private matchup(g: Game): string {
    return `${g.home_team?.name ?? '?'} ${extractText(app.translator.trans('ernestdefoe-picks.lib.common.vs'))} ${g.away_team?.name ?? '?'}`;
  }

  private when(g: Game): string {
    if (!g.match_date) return '';
    try {
      const d = new Date(g.match_date);
      const day = d.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        ...(g.time_tbd ? { timeZone: 'America/New_York' } : {}),
      });
      return g.time_tbd ? day : `${day} ${d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    } catch (e) {
      return '';
    }
  }
}
