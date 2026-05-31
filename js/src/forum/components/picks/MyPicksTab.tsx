import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class MyPicksTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;
    const week = state.currentWeek();
    const idx = state.weeks.findIndex((w) => w.id === state.currentWeekId);
    const myGames = state.games.filter((g) => g.my_pick);
    const correct = myGames.filter((g) => g.my_pick?.is_correct === true).length;
    const incorrect = myGames.filter((g) => g.my_pick?.is_correct === false).length;

    return (
      <div className="PicksTab">
        <div className="PicksWeekNav">
          <div>
            <div className="PicksWeekNav-title">
              {app.translator.trans('ernestdefoe-picks.lib.nav.my_picks')} · {week?.name}
            </div>
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

        {myGames.length > 0 && (
          <div className="PicksStatusBar">
            <span>
              {app.translator.trans('ernestdefoe-picks.lib.common.picked')}: <strong>{myGames.length}</strong>
            </span>
            <span>
              ✓ <strong>{correct}</strong>
            </span>
            <span>
              ✗ <strong>{incorrect}</strong>
            </span>
            <span>
              {app.translator.trans('ernestdefoe-picks.lib.common.points')}: <strong>{correct}</strong>
            </span>
          </div>
        )}

        {state.gamesLoading ? (
          <LoadingIndicator />
        ) : myGames.length === 0 ? (
          <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.no_data')}</div>
        ) : (
          myGames.map((game) => {
            const side = game.my_pick!.selected_outcome;
            const team = side === 'home' ? game.home_team : game.away_team;
            const oppTeam = side === 'home' ? game.away_team : game.home_team;
            const isCorrect = game.my_pick!.is_correct === true;
            const isIncorrect = game.my_pick!.is_correct === false;

            return (
              <div
                className={`PicksMyPickRow ${isCorrect ? 'PicksMyPickRow--correct' : isIncorrect ? 'PicksMyPickRow--incorrect' : ''}`}
                key={String(game.id)}
              >
                <div className="PicksMyPickRow-logos">
                  {team?.logo_url && (
                    <>
                      <img
                        src={team.logo_url}
                        alt={team.name}
                        className="PicksMyPickRow-logo PicksMyPickRow-logo--light"
                      />
                      <img
                        src={team.logo_dark_url || team.logo_url}
                        alt={team.name}
                        className="PicksMyPickRow-logo PicksMyPickRow-logo--dark"
                      />
                    </>
                  )}
                  <span className="PicksMyPickRow-sep">vs</span>
                  {oppTeam?.logo_url && (
                    <>
                      <img
                        src={oppTeam.logo_url}
                        alt={oppTeam.name}
                        className="PicksMyPickRow-logo PicksMyPickRow-logo--light"
                      />
                      <img
                        src={oppTeam.logo_dark_url || oppTeam.logo_url}
                        alt={oppTeam.name}
                        className="PicksMyPickRow-logo PicksMyPickRow-logo--dark"
                      />
                    </>
                  )}
                </div>
                <div className="PicksMyPickRow-info">
                  <div className="PicksMyPickRow-matchup">
                    {team?.name} vs {oppTeam?.name}
                  </div>
                  <div className="PicksMyPickRow-pick">
                    {app.translator.trans('ernestdefoe-picks.lib.common.picked')}: <strong>{team?.name}</strong>
                    {game.status === 'finished' && game.home_score !== null && (
                      <span>
                        {' '}
                        · {game.home_score}–{game.away_score}
                      </span>
                    )}
                  </div>
                </div>
                <div className="PicksMyPickRow-status">
                  {isCorrect && (
                    <span className="PicksTag PicksTag--correct">
                      +{game.my_pick!.confidence ?? 1} pt
                      {(game.my_pick!.confidence ?? 1) !== 1 ? 's' : ''}
                    </span>
                  )}
                  {isIncorrect && <span className="PicksTag PicksTag--incorrect">+0 pts</span>}
                  {!isCorrect && !isIncorrect && (
                    <span className="PicksTag PicksTag--pending">
                      Pending
                      {app.forum.attribute('picksConfidenceMode') && game.my_pick!.confidence
                        ? ` · ${game.my_pick!.confidence}`
                        : ''}
                    </span>
                  )}
                </div>
              </div>
            );
          })
        )}
      </div>
    );
  }
}
