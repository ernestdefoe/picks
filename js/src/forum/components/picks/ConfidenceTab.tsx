import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type PicksState from './PicksState';
import type { C10Game } from './types';
import WeekNav from './WeekNav';
import PicksSkeleton from '../PicksSkeleton';
import { startDrag } from '../../../common/sortable';
import extractText from 'flarum/common/utils/extractText';
import crestUrl from '../../../common/crest';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

const t = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.forum.confidence.' + key, params as any);

/** For attributes, which need a plain string. */
const ts = (key: string, params?: Record<string, unknown>) => extractText(t(key, params));

/** Refusal codes the server sends that have their own words. */
const ERRORS = ['locked', 'duplicate_value', 'out_of_range', 'not_in_contest', 'bad_tiebreaker', 'disabled'];

/**
 * The member's Confidence board: the week's chosen games, a winner for each,
 * and a ranking from N (surest) down to 1.
 *
 * Three ways to rank, all doing the same thing to the same state: drag a row by
 * its handle, pick a number from the row's own menu, or focus the handle and
 * press Alt+Up / Alt+Down. Locked games sit in their own list underneath, with
 * the value they kept, because nothing can move them any more.
 */
export default class ConfidenceTab extends Component<TabAttrs> {
  oninit(vnode: Mithril.Vnode<TabAttrs, this>) {
    super.oninit(vnode);
    const state = this.attrs.state;
    if (state.currentWeekId && state.c10.weekId !== state.currentWeekId) {
      state.c10.load(state.currentWeekId);
    }
  }

  view(): Mithril.Children {
    const state = this.attrs.state;
    const c10 = state.c10;
    const board = c10.board;

    return (
      <div className="PicksTab C10">
        {WeekNav(state)}

        {c10.loading && !board ? (
          <PicksSkeleton surface="confidence" fallback={430} rows={4} />
        ) : !board || board.games.length === 0 ? (
          <div className="PicksEmpty">{board?.week_open ? t('not_chosen') : t('chosen_when_open')}</div>
        ) : (
          this.content()
        )}
      </div>
    );
  }

  private content(): Mithril.Children {
    const c10 = this.attrs.state.c10;
    const board = c10.board!;
    const n = board.size;
    const locked = c10.lockedGames();
    const playing = c10.canPlay();

    return (
      <div>
        <p className="C10-intro">
          {t('intro', { count: n })}{' '}
          {board.penalty === 'half' && t('penalty_half')}
          {board.penalty === 'full' && t('penalty_full')}
        </p>

        <div className="PicksStatusBar C10-status" aria-live="polite">
          {playing && (
            <span>
              {app.translator.trans('ernestdefoe-picks.lib.common.picked')}:{' '}
              <strong>
                {c10.pickedCount()} / {board.games.length}
              </strong>
            </span>
          )}
          {board.my_score && (
            <span>
              {app.translator.trans('ernestdefoe-picks.lib.common.points')}: <strong>{board.my_score.total_points}</strong>
            </span>
          )}
          <span className="C10-saveState">
            {c10.saving ? t('saving') : c10.error ? <span className="C10-error" role="alert">{t('errors.' + (ERRORS.includes(c10.error) ? c10.error : 'generic'))}</span> : c10.saved ? t('saved') : ''}
          </span>
        </div>

        {!playing && <div className="PicksWeekLocked">{t(app.session.user ? 'no_permission' : 'log_in')}</div>}
        {!board.week_open && <div className="PicksWeekLocked"><i className="fas fa-lock" /> {t('week_closed')}</div>}

        {c10.order.length > 0 && (
          <div>
            <h4 className="C10-heading">
              {t('your_ranking')}
              {playing && c10.order.length > 1 && <span className="C10-hint">{t('drag_hint')}</span>}
            </h4>
            <ol className="C10-list" aria-label={ts('your_ranking')}>
              {c10.order.map((id, index) => this.row(c10.game(id)!, index, false))}
            </ol>
          </div>
        )}

        {locked.length > 0 && (
          <div>
            <h4 className="C10-heading">{t('locked_heading')}</h4>
            <ol className="C10-list C10-list--locked">{locked.map((g, index) => this.row(g, index, true))}</ol>
          </div>
        )}

        {this.tiebreaker()}
      </div>
    );
  }

  private row(game: C10Game, index: number, locked: boolean): Mithril.Children {
    const state = this.attrs.state;
    const c10 = state.c10;
    const playing = c10.canPlay() && !locked;
    const value = locked ? game.my_pick?.confidence ?? null : c10.values[game.id];
    const outcome = locked ? game.my_pick?.selected_outcome ?? null : c10.outcomes[game.id];
    const result = game.my_pick?.is_correct;
    const penalty = c10.board!.penalty;

    let cls = 'C10Row';
    if (outcome) cls += ' C10Row--picked';
    if (result === true) cls += ' C10Row--correct';
    if (result === false) cls += ' C10Row--incorrect';
    if (locked) cls += ' C10Row--locked';

    const label = this.matchup(game);

    return (
      <li className={cls} key={String(game.id)} data-c10-row={String(game.id)}>
        {playing ? (
          <button
            type="button"
            className="C10Row-handle"
            data-c10-handle={String(game.id)}
            aria-label={ts('handle_label', { game: label, value: String(value) })}
            title={ts('handle_title')}
            onpointerdown={(e: PointerEvent) =>
              startDrag(e, {
                list: (e.currentTarget as HTMLElement).closest('.C10-list') as HTMLElement,
                rowSelector: '.C10Row',
                from: index,
                onDrop: (from, to) => {
                  c10.move(from, to);
                  this.refocus(game.id);
                },
              })
            }
            onkeydown={(e: KeyboardEvent) => {
              if (!e.altKey || (e.key !== 'ArrowUp' && e.key !== 'ArrowDown')) return;
              e.preventDefault();
              c10.move(index, index + (e.key === 'ArrowUp' ? -1 : 1));
              this.refocus(game.id);
            }}
          >
            <i className="fas fa-grip-vertical" aria-hidden="true" />
          </button>
        ) : (
          <span className="C10Row-handle C10Row-handle--static" aria-hidden="true">
            <i className={locked ? 'fas fa-lock' : 'fas fa-grip-vertical'} />
          </span>
        )}

        <span className="C10Row-value" aria-hidden="true">
          {value ?? '–'}
        </span>

        <div className="C10Row-body">
          <div className="C10Row-meta">
            <span>{this.when(game)}</span>
            {game.broadcast && <span>· {game.broadcast}</span>}
            {game.is_tiebreaker && <span className="C10Row-tb">{t('tiebreaker_tag')}</span>}
          </div>
          <div className="C10Row-teams" role="group" aria-label={label}>
            {this.team(game, 'home', outcome, playing)}
            <span className={`C10Row-vs ${game.status === 'finished' && game.home_score !== null ? 'C10Row-vs--score' : ''}`}>
              {game.home_score !== null && game.status === 'finished' ? `${game.home_score}–${game.away_score}` : app.translator.trans('ernestdefoe-picks.lib.common.vs')}
            </span>
            {this.team(game, 'away', outcome, playing)}
          </div>
          {locked && !game.my_pick && <span className="PicksTag PicksTag--locked">{t('no_pick')}</span>}
          {result === true && <span className="PicksTag PicksTag--correct">{t('correct', { points: value })}</span>}
          {result === false && (
            <span className="PicksTag PicksTag--incorrect">
              {penalty === 'none' ? t('incorrect') : t('incorrect_penalty', { points: penalty === 'full' ? value : Math.floor((value || 0) / 2) })}
            </span>
          )}
        </div>

        {playing && (
          // Core's Select markup, so the menu carries the theme's caret and
          // reads as a menu rather than a grey box with a number in it.
          <span className="Select C10Row-select">
            <select
              className="Select-input FormControl C10Row-selectInput"
              aria-label={ts('value_label', { game: label })}
              value={String(value)}
              onchange={(e: Event) => c10.setValue(game.id, parseInt((e.target as HTMLSelectElement).value, 10))}
            >
              {c10.freeValues().map((v) => (
                <option value={String(v)} selected={v === value}>
                  {v}
                </option>
              ))}
            </select>
            <i className="icon fas fa-sort Select-caret" aria-hidden="true" />
          </span>
        )}
      </li>
    );
  }

  private team(game: C10Game, side: 'home' | 'away', outcome: string | null, playing: boolean): Mithril.Children {
    const c10 = this.attrs.state.c10;
    const team = side === 'home' ? game.home_team : game.away_team;
    const rank = side === 'home' ? game.home_rank : game.away_rank;
    const finished = game.status === 'finished';

    let cls = 'C10Team';
    if (outcome === side) cls += ' C10Team--selected';
    if (finished && game.result === side) cls += ' C10Team--winner';
    if (finished && game.result && game.result !== side) cls += ' C10Team--loser';

    return (
      <button
        type="button"
        className={cls}
        disabled={!playing}
        aria-pressed={outcome === side ? 'true' : 'false'}
        onclick={() => playing && c10.pick(game.id, side)}
      >
        {team?.logo_url ? (
          <span className="C10Team-logo">
            <img src={crestUrl(team.logo_url, 32)} alt="" className="PicksTeamBtn-logo-light" loading="lazy" decoding="async" />
            <img src={crestUrl(team.logo_dark_url || team.logo_url, 32)} alt="" className="PicksTeamBtn-logo-dark" loading="lazy" decoding="async" />
          </span>
        ) : (
          <span className="C10Team-logo C10Team-logo--initial">{(team?.abbreviation || team?.name || '?').charAt(0)}</span>
        )}
        <span className="C10Team-name">
          {rank ? <span className="PicksTeamBtn-rank">#{rank}</span> : null}
          {team?.name || '—'}
        </span>
      </button>
    );
  }

  private tiebreaker(): Mithril.Children {
    const c10 = this.attrs.state.c10;
    const tb = c10.board!.tiebreaker;
    if (!tb) return null;

    const game = c10.game(tb.event_id);
    const label = game ? this.matchup(game) : '';
    const editable = tb.can_change && c10.canPlay();

    return (
      <div className="C10-tiebreaker">
        <label className="C10-tiebreaker-label" for="C10-tiebreaker-input">
          <i className="fas fa-balance-scale" aria-hidden="true" /> {t('tiebreaker_label', { game: label })}
        </label>
        <div className="C10-tiebreaker-row">
          <input
            id="C10-tiebreaker-input"
            className="FormControl C10-tiebreaker-input"
            type="text"
            inputmode="numeric"
            pattern="[0-9]*"
            maxlength="3"
            placeholder={ts('tiebreaker_placeholder')}
            value={c10.tiebreaker}
            disabled={!editable}
            oninput={(e: InputEvent) => c10.setTiebreaker((e.target as HTMLInputElement).value)}
          />
          <span className="C10-tiebreaker-help">
            {tb.actual_total !== null ? t('tiebreaker_actual', { total: tb.actual_total }) : t('tiebreaker_help')}
          </span>
        </div>
      </div>
    );
  }

  private matchup(game: C10Game): string {
    return `${game.home_team?.name ?? '?'} ${app.translator.trans('ernestdefoe-picks.lib.common.vs')} ${game.away_team?.name ?? '?'}`;
  }

  private when(game: C10Game): string {
    if (!game.match_date) return '';
    try {
      const d = new Date(game.match_date);
      const day = d.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        ...(game.time_tbd ? { timeZone: 'America/New_York' } : {}),
      });
      if (game.time_tbd) return `${day} · ${app.translator.trans('ernestdefoe-picks.forum.game.time_tba')}`;
      return `${day} · ${d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })}`;
    } catch (e) {
      return '';
    }
  }

  /** Keep keyboard focus on the row that moved, wherever it landed. */
  private refocus(id: number): void {
    m.redraw.sync();
    requestAnimationFrame(() => {
      (document.querySelector(`[data-c10-handle="${id}"]`) as HTMLElement | null)?.focus();
    });
  }
}
