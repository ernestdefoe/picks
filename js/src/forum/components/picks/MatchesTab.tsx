import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';
import type { Game } from './types';
import WeekNav from './WeekNav';
import crestUrl from '../../../common/crest';

const t = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.forum.games.' + key, params as any);

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class MatchesTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;
    const picked = state.weeksMeta.picked || 0;
    const total = state.weeksMeta.total || 0;

    return (
      <div className="PicksTab">
        {WeekNav(state)}

        {app.session.user && total > 0 && (
          <div className="PicksStatusBar">
            <span>
              {app.translator.trans('ernestdefoe-picks.lib.common.picked')}:{' '}
              <strong>
                {picked} / {total}
              </strong>
            </span>
          </div>
        )}

        {!state.weekOpen && !state.gamesLoading && state.games.length > 0 && (
          <div className="PicksWeekLocked">
            <i className="fas fa-lock" /> {t('week_not_open')}
          </div>
        )}

        {state.gamesLoading ? (
          <LoadingIndicator />
        ) : state.games.length === 0 ? (
          <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.no_matches')}</div>
        ) : (
          state.games.map((game) => this.renderGameCard(game))
        )}
      </div>
    );
  }

  private formatDate(dateStr: string | null, timeTbd = false): string {
    if (!dateStr) return '';
    try {
      // 🚨 An unannounced kickoff is a placeholder of midnight Eastern on game
      // day. Read in the visitor's own zone that is the evening BEFORE west of
      // New York, so the date is read where the placeholder was set.
      return new Date(dateStr).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        ...(timeTbd ? { timeZone: 'America/New_York' } : {}),
      });
    } catch {
      return dateStr;
    }
  }

  private formatTime(dateStr: string): string {
    return new Date(dateStr).toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  private formatKickoff(game: Game): string {
    if (game.time_tbd) return app.translator.trans('ernestdefoe-picks.forum.game.time_tba') as string;
    try {
      return this.formatTime(game.match_date as string);
    } catch {
      return '';
    }
  }

  /**
   * 🚨 When picks close, said out loud. The card used to show the date and
   * nothing else, so a game that locked at 8am for an unannounced kickoff read
   * "Locked" all afternoon with no clue why, and the lock offset looked like it
   * was counting days. Shown only when it is not simply the kickoff.
   */
  private lockLabel(game: Game): Mithril.Children {
    if (!game.cutoff_date || !game.match_date) return null;
    const cutoff = new Date(game.cutoff_date);
    if (!game.time_tbd && cutoff.getTime() === new Date(game.match_date).getTime()) return null;
    try {
      const sameDay = cutoff.toDateString() === new Date().toDateString()
        || cutoff.toDateString() === new Date(game.match_date).toDateString();
      const when = sameDay
        ? this.formatTime(game.cutoff_date)
        : cutoff.toLocaleString(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' });
      return <span>· {app.translator.trans('ernestdefoe-picks.forum.game.locks_at', { time: when })}</span>;
    } catch {
      return null;
    }
  }

  private renderTeamButton(game: Game, side: 'home' | 'away'): Mithril.Children {
    const state = this.attrs.state;
    const team = side === 'home' ? game.home_team : game.away_team;
    const isFinished = game.status === 'finished';
    const myOutcome = game.my_pick?.selected_outcome;
    const isSelected = myOutcome === side;
    const isWinner = isFinished && game.result === side;
    const isLoser = isFinished && game.result !== null && game.result !== side;

    /*
     * 🚨 A guest can press this, and that is the point.
     *
     * `can_pick` is false for everybody who is not signed in, so the button was
     * `disabled` and the click never fired — the pick'em's one conversion
     * moment was a control that did nothing at all. A visitor is offered the
     * sign-up instead, and only for a game that has not started: inviting
     * somebody to join so they can pick a game already being played is worse
     * than not asking.
     */
    const guest = !app.session.user;
    const openToGuest = guest && game.status === 'scheduled';

    let cls = 'PicksTeamBtn';
    if (isSelected) cls += ' PicksTeamBtn--selected';
    if (isWinner) cls += ' PicksTeamBtn--winner';
    if (isLoser) cls += ' PicksTeamBtn--loser';
    if (!game.can_pick && !isFinished && !openToGuest) cls += ' PicksTeamBtn--locked';

    const rank = side === 'home' ? game.home_rank : game.away_rank;

    const logoUrl = team?.logo_url;
    const logoDarkUrl = team?.logo_dark_url || logoUrl;

    return (
      <button
        className={cls}
        disabled={(!game.can_pick && !openToGuest) || state.submitting[game.id] || undefined}
        onclick={() => (game.can_pick || openToGuest) && state.submitPick(game, side)}
      >
        <div className="PicksTeamBtn-logo">
          {logoUrl ? (
            <>
              {/* Lazy, so the variant CSS hides is never fetched at all. */}
              <img src={crestUrl(logoUrl, 96)} alt={team?.name || ''} className="PicksTeamBtn-logo-light" loading="lazy" decoding="async" />
              <img src={crestUrl(logoDarkUrl!, 96)} alt={team?.name || ''} className="PicksTeamBtn-logo-dark" loading="lazy" decoding="async" />
            </>
          ) : (
            <span>{(team?.abbreviation || team?.name || '?').charAt(0)}</span>
          )}
        </div>
        <div className="PicksTeamBtn-name">
          {/* 🚨 Inside the name, not above it. These buttons are a fixed grid —
              logo, name, conference — and a rank on a line of its own would
              make a ranked side taller than the one beside it, so every card
              with one ranked team in it would sit crooked. */}
          {rank ? <span className="PicksTeamBtn-rank">#{rank}</span> : null}
          {team?.name || '—'}
        </div>
        <div className="PicksTeamBtn-conf">{team?.conference || ''}</div>
      </button>
    );
  }

  private renderGameCard(game: Game): Mithril.Children {
    const state = this.attrs.state;
    const isFinished = game.status === 'finished';
    const isCorrect = game.my_pick?.is_correct === true;
    const isIncorrect = game.my_pick?.is_correct === false;

    let cardCls = 'PicksGameCard';
    if (isCorrect) cardCls += ' PicksGameCard--correct';
    else if (isIncorrect) cardCls += ' PicksGameCard--incorrect';
    else if (game.my_pick) cardCls += ' PicksGameCard--picked';

    return (
      <div className={cardCls} key={String(game.id)}>
        <div className="PicksGameCard-meta">
          <span>{this.formatDate(game.match_date, game.time_tbd)}</span>
          {game.status === 'scheduled' && game.match_date && <span>· {this.formatKickoff(game)}</span>}
          {game.neutral_site && <span>· {t('neutral_site')}</span>}
          {game.can_pick && this.lockLabel(game)}
          {!game.can_pick && game.status === 'scheduled' && <span>· {app.translator.trans('ernestdefoe-picks.forum.game.locked')}</span>}
        </div>

        <div className="PicksGameCard-teams">
          {this.renderTeamButton(game, 'home')}

          <div className="PicksGameCard-vs">
            {isFinished && game.home_score !== null ? (
              <span className="PicksGameCard-score">
                {game.home_score}–{game.away_score}
              </span>
            ) : (
              <span>{app.translator.trans('ernestdefoe-picks.lib.common.vs')}</span>
            )}
          </div>

          {this.renderTeamButton(game, 'away')}
        </div>

        {(game.my_pick || (!game.can_pick && game.status === 'scheduled')) && (
          <div className="PicksGameCard-result">
            {isCorrect && (
              <span className="PicksTag PicksTag--correct">
                ✓ {t('correct', { count: game.my_pick?.confidence ?? 1 })}
              </span>
            )}
            {isIncorrect && <span className="PicksTag PicksTag--incorrect">✗ {t('incorrect')}</span>}
            {game.my_pick && !isFinished && (
              <span className="PicksTag PicksTag--pending">{t('pending')}</span>
            )}
            {!game.can_pick && game.status === 'scheduled' && !game.my_pick && (
              <span className="PicksTag PicksTag--locked">{t('no_pick')}</span>
            )}
          </div>
        )}

        {/* Confidence selector — shown when mode is on, pick is made, game is open */}
        {app.forum.attribute('picksConfidenceMode') && game.my_pick && game.can_pick && !isFinished && (
          <div className="PicksConfidence">
            <span className="PicksConfidence-label">{t('confidence_label')}</span>
            <div className="PicksConfidence-buttons">
              {[1, 2, 3, 4, 5, 6, 7, 8, 9, 10].map((n) => (
                <button
                  key={n}
                  className={`PicksConfidence-btn ${game.my_pick?.confidence === n ? 'PicksConfidence-btn--active' : ''}`}
                  onclick={() => state.submitConfidence(game, n)}
                >
                  {n}
                </button>
              ))}
            </div>
            {app.forum.attribute('picksConfidencePenalty') !== 'none' && (
              <span className="PicksConfidence-hint">
                {app.forum.attribute('picksConfidencePenalty') === 'full' ? t('confidence_hint_full') : t('confidence_hint_half')}
              </span>
            )}
          </div>
        )}
      </div>
    );
  }
}
