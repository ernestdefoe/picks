import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type PicksState from './PicksState';
import type { Game, GameTeam } from './types';
import PicksSkeleton from '../PicksSkeleton';
import crestUrl from '../../../common/crest';
import { awayFirst, score, separator } from '../../../common/matchup';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class MyPicksTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;
    const myGames = state.games.filter((g) => g.my_pick);

    return (
      <div className="PicksTab">
        {this.weekNav()}
        {this.statusBar(myGames)}
        {this.list(myGames)}
      </div>
    );
  }

  private weekNav(): Mithril.Children {
    const state = this.attrs.state;
    const week = state.currentWeek();
    const idx = state.weeks.findIndex((w) => w.id === state.currentWeekId);

    return (
      <div className="PicksWeekNav">
        <div>
          <div className="PicksWeekNav-title">
            {app.translator.trans('ernestdefoe-picks.lib.nav.my_picks')} · {week?.name}
          </div>
        </div>
        <div className="PicksWeekNav-arrows">
          {state.thisWeekId && state.thisWeekId !== state.currentWeekId && (
            <Button className="Button PicksWeekNav-thisWeek" icon="fas fa-calendar-day" onclick={() => state.goToThisWeek()}>
              {app.translator.trans('ernestdefoe-picks.lib.nav.this_week')}
            </Button>
          )}
          <Button className="Button Button--icon" icon="fas fa-chevron-left" disabled={idx <= 0} onclick={() => state.prevWeek()} />
          <Button
            className="Button Button--icon"
            icon="fas fa-chevron-right"
            disabled={idx >= state.weeks.length - 1}
            onclick={() => state.nextWeek()}
          />
        </div>
      </div>
    );
  }

  private statusBar(myGames: Game[]): Mithril.Children {
    if (myGames.length === 0) return false;

    const correct = myGames.filter((g) => g.my_pick?.is_correct === true).length;
    const incorrect = myGames.filter((g) => g.my_pick?.is_correct === false).length;

    return (
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
    );
  }

  private list(myGames: Game[]): Mithril.Children {
    if (this.attrs.state.gamesLoading) {
      return <PicksSkeleton surface="myPicks" fallback={295} rows={5} variant="rows" />;
    }

    if (myGames.length === 0) {
      return <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.no_data')}</div>;
    }

    return myGames.map((game) => this.row(game));
  }

  private row(game: Game): Mithril.Children {
    const side = game.my_pick!.selected_outcome;
    const team = side === 'home' ? game.home_team : game.away_team;
    const oppTeam = side === 'home' ? game.away_team : game.home_team;
    // By default the row leads with the team that was picked; "Away @ Home"
    // fixes the order instead, since the "@" only reads true one way round.
    const [first, second] = awayFirst() ? [game.away_team, game.home_team] : [team, oppTeam];
    const sep = separator(game.neutral_site);
    const isCorrect = game.my_pick!.is_correct === true;
    const isIncorrect = game.my_pick!.is_correct === false;

    return (
      <div
        className={`PicksMyPickRow ${isCorrect ? 'PicksMyPickRow--correct' : isIncorrect ? 'PicksMyPickRow--incorrect' : ''}`}
        key={String(game.id)}
      >
        <div className="PicksMyPickRow-logos">
          {this.logos(first)}
          <span className="PicksMyPickRow-sep">{sep}</span>
          {this.logos(second)}
        </div>
        <div className="PicksMyPickRow-info">
          <div className="PicksMyPickRow-matchup">
            {first?.name} {sep} {second?.name}
          </div>
          <div className="PicksMyPickRow-pick">
            {app.translator.trans('ernestdefoe-picks.lib.common.picked')}: <strong>{team?.name}</strong>
            {game.status === 'finished' && game.home_score !== null && <span> · {score(game.home_score, game.away_score)}</span>}
          </div>
        </div>
        <div className="PicksMyPickRow-status">{this.statusTag(game, isCorrect, isIncorrect)}</div>
      </div>
    );
  }

  private logos(team: GameTeam | null): Mithril.Children {
    return (
      team?.logo_url && (
        <>
          <img
            src={crestUrl(team.logo_url, 36)}
            alt={team.name}
            className="PicksMyPickRow-logo PicksMyPickRow-logo--light"
            loading="lazy"
            decoding="async"
          />
          <img
            src={crestUrl(team.logo_dark_url || team.logo_url, 36)}
            alt={team.name}
            className="PicksMyPickRow-logo PicksMyPickRow-logo--dark"
            loading="lazy"
            decoding="async"
          />
        </>
      )
    );
  }

  private statusTag(game: Game, isCorrect: boolean, isIncorrect: boolean): Mithril.Children {
    return [
      isCorrect && (
        <span className="PicksTag PicksTag--correct">
          {app.translator.trans('ernestdefoe-picks.forum.my_picks.points', { count: game.my_pick!.confidence ?? 1 })}
        </span>
      ),
      isIncorrect && (
        <span className="PicksTag PicksTag--incorrect">{app.translator.trans('ernestdefoe-picks.forum.my_picks.points', { count: 0 })}</span>
      ),
      !isCorrect && !isIncorrect && (
        <span className="PicksTag PicksTag--pending">
          {app.forum.attribute('picksConfidenceMode') && game.my_pick!.confidence
            ? app.translator.trans('ernestdefoe-picks.forum.my_picks.pending_value', { value: game.my_pick!.confidence })
            : app.translator.trans('ernestdefoe-picks.forum.my_picks.pending')}
        </span>
      ),
    ];
  }
}
