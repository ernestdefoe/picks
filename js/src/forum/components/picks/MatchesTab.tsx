import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';
import type { Game } from './types';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class MatchesTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;
    const week = state.currentWeek();
    const idx = state.weeks.findIndex((w) => w.id === state.currentWeekId);
    const picked = state.weeksMeta.picked || 0;
    const total = state.weeksMeta.total || 0;

    return (
      <div className="PicksTab">
        <div className="PicksWeekNav">
          <div>
            <div className="PicksWeekNav-title">{week?.name || '—'}</div>
            {week?.start_date && (
              <div className="PicksWeekNav-dates">
                {week.start_date} – {week.end_date}
              </div>
            )}
          </div>
          <div className="PicksWeekNav-arrows">
            <Button
              className="Button Button--icon"
              icon="fas fa-chevron-left"
              disabled={idx <= 0}
              onclick={() => state.prevWeek()}
            />
            <Button
              className="Button Button--icon"
              icon="fas fa-chevron-right"
              disabled={idx >= state.weeks.length - 1}
              onclick={() => state.nextWeek()}
            />
          </div>
        </div>

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
            <i className="fas fa-lock" /> Picks for this week are not yet open. Check back soon!
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

  private formatDate(dateStr: string | null): string {
    if (!dateStr) return '';
    try {
      return new Date(dateStr).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
      });
    } catch {
      return dateStr;
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

    let cls = 'PicksTeamBtn';
    if (isSelected) cls += ' PicksTeamBtn--selected';
    if (isWinner) cls += ' PicksTeamBtn--winner';
    if (isLoser) cls += ' PicksTeamBtn--loser';
    if (!game.can_pick && !isFinished) cls += ' PicksTeamBtn--locked';

    const logoUrl = team?.logo_url;
    const logoDarkUrl = team?.logo_dark_url || logoUrl;

    return (
      <button
        className={cls}
        disabled={!game.can_pick || state.submitting[game.id] || undefined}
        onclick={() => game.can_pick && state.submitPick(game, side)}
      >
        <div className="PicksTeamBtn-logo">
          {logoUrl ? (
            <>
              <img src={logoUrl} alt={team?.name || ''} className="PicksTeamBtn-logo-light" />
              <img src={logoDarkUrl} alt={team?.name || ''} className="PicksTeamBtn-logo-dark" />
            </>
          ) : (
            <span>{(team?.abbreviation || team?.name || '?').charAt(0)}</span>
          )}
        </div>
        <div className="PicksTeamBtn-name">{team?.name || '—'}</div>
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
          <span>{this.formatDate(game.match_date)}</span>
          {game.neutral_site && <span>· Neutral site</span>}
          {!game.can_pick && game.status === 'scheduled' && <span>· Locked</span>}
        </div>

        <div className="PicksGameCard-teams">
          {this.renderTeamButton(game, 'home')}

          <div className="PicksGameCard-vs">
            {isFinished && game.home_score !== null ? (
              <span className="PicksGameCard-score">
                {game.home_score}–{game.away_score}
              </span>
            ) : (
              <span>vs</span>
            )}
          </div>

          {this.renderTeamButton(game, 'away')}
        </div>

        {(game.my_pick || (!game.can_pick && game.status === 'scheduled')) && (
          <div className="PicksGameCard-result">
            {isCorrect && (
              <span className="PicksTag PicksTag--correct">
                ✓ Correct · +{game.my_pick?.confidence ?? 1} pt
                {(game.my_pick?.confidence ?? 1) !== 1 ? 's' : ''}
              </span>
            )}
            {isIncorrect && <span className="PicksTag PicksTag--incorrect">✗ Incorrect</span>}
            {game.my_pick && !isFinished && (
              <span className="PicksTag PicksTag--pending">Pick saved · awaiting result</span>
            )}
            {!game.can_pick && game.status === 'scheduled' && !game.my_pick && (
              <span className="PicksTag PicksTag--locked">Cutoff passed · no pick</span>
            )}
          </div>
        )}

        {/* Confidence selector — shown when mode is on, pick is made, game is open */}
        {app.forum.attribute('picksConfidenceMode') && game.my_pick && game.can_pick && !isFinished && (
          <div className="PicksConfidence">
            <span className="PicksConfidence-label">Confidence:</span>
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
                {app.forum.attribute('picksConfidencePenalty') === 'full' ? '±pts' : '−½pts if wrong'}
              </span>
            )}
          </div>
        )}
      </div>
    );
  }
}
