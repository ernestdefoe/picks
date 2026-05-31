import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class LeaderboardTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;
    const isOffSeason = state.lbContext?.is_off_season ?? false;
    const retentionExpired = state.lbContext?.retention_expired ?? false;
    const lastSeasonName = state.lbContext?.last_season_name ?? null;
    const daysSinceEnded = state.lbContext?.days_since_ended ?? null;
    const noSchedule = !state.currentWeekId && !isOffSeason;

    const scopes = [
      {
        key: 'week',
        label: app.translator.trans('ernestdefoe-picks.lib.common.week'),
      },
      {
        key: 'season',
        label: app.translator.trans('ernestdefoe-picks.lib.common.season'),
      },
      { key: 'alltime', label: 'All Time' },
    ];

    // During off-season retention, label the scope buttons to clarify they show final standings
    const scopeLabel = (key: string) => {
      if (isOffSeason && !retentionExpired && key !== 'alltime') {
        return key === 'week'
          ? 'Final Week'
          : (lastSeasonName ?? app.translator.trans('ernestdefoe-picks.lib.common.season'));
      }
      return key === 'week'
        ? app.translator.trans('ernestdefoe-picks.lib.common.week')
        : key === 'season'
          ? app.translator.trans('ernestdefoe-picks.lib.common.season')
          : 'All Time';
    };

    const emptyMessage = () => {
      if (noSchedule) return 'No schedule has been imported yet. Check back soon!';
      if (isOffSeason && retentionExpired) {
        return `The ${lastSeasonName ?? 'season'} has ended. Final standings are available in the History tab.`;
      }
      if (isOffSeason && !retentionExpired && daysSinceEnded !== null) {
        return `Final standings · ${lastSeasonName ?? 'Season'} ended ${daysSinceEnded} day${daysSinceEnded !== 1 ? 's' : ''} ago`;
      }
      return app.translator.trans('ernestdefoe-picks.lib.messages.no_data') as string;
    };

    return (
      <div className="PicksTab">
        <div className="PicksLbScopes">
          {scopes.map((s) => (
            <button
              key={s.key}
              className={`PicksLbScope ${state.lbScope === s.key ? 'PicksLbScope--active' : ''}`}
              onclick={() => {
                state.lbScope = s.key;
                state.loadLeaderboard();
              }}
            >
              {scopeLabel(s.key)}
            </button>
          ))}
        </div>

        {isOffSeason && !retentionExpired && (
          <div className="PicksOffSeasonBanner">
            <i className="fas fa-flag-checkered" /> Season complete · Final standings locked
            {daysSinceEnded !== null && ` · ${daysSinceEnded}d ago`}
          </div>
        )}

        {state.lbLoading ? (
          <LoadingIndicator />
        ) : state.leaderboard.length === 0 ? (
          <div className="PicksEmpty">{emptyMessage()}</div>
        ) : (
          <div className="PicksLeaderboard">
            <div className="PicksLeaderboard-head">
              <div>#</div>
              <div>{app.translator.trans('ernestdefoe-picks.lib.common.team')}</div>
              <div className="PicksLeaderboard-right">Pts</div>
              <div className="PicksLeaderboard-right">W–L</div>
              <div className="PicksLeaderboard-right">Acc</div>
            </div>
            {state.leaderboard.map((entry) => (
              <div
                className={`PicksLeaderboard-row
                  ${entry.is_me ? 'PicksLeaderboard-row--me' : ''}
                  ${entry.rank === 1 ? 'PicksLeaderboard-row--gold' : ''}
                  ${entry.rank === 2 ? 'PicksLeaderboard-row--silver' : ''}
                  ${entry.rank === 3 ? 'PicksLeaderboard-row--bronze' : ''}
                `}
                key={String(entry.user_id)}
              >
                <div className={`PicksLeaderboard-rank ${entry.rank === 1 ? 'PicksLeaderboard-rank--gold' : ''}`}>
                  {entry.rank === 1 ? '🥇' : entry.rank === 2 ? '🥈' : entry.rank === 3 ? '🥉' : entry.rank}
                </div>
                <div className="PicksLeaderboard-user">
                  {entry.avatar_url ? (
                    <img src={entry.avatar_url} alt={entry.display_name} className="PicksAvatar" />
                  ) : (
                    <div className="PicksAvatar PicksAvatar--initials">{(entry.display_name || '?').charAt(0)}</div>
                  )}
                  <span>{entry.display_name}</span>
                  {entry.movement !== null && entry.movement !== 0 && (
                    <span
                      className={`PicksMovement ${entry.movement > 0 ? 'PicksMovement--up' : 'PicksMovement--down'}`}
                    >
                      {entry.movement > 0 ? `↑${entry.movement}` : `↓${Math.abs(entry.movement)}`}
                    </span>
                  )}
                </div>
                <div className="PicksLeaderboard-right PicksLeaderboard-pts">{entry.total_points}</div>
                <div className="PicksLeaderboard-right PicksLeaderboard-wl">
                  {entry.correct_picks}–{entry.total_picks - entry.correct_picks}
                </div>
                <div className="PicksLeaderboard-right PicksLeaderboard-acc">{entry.accuracy.toFixed(0)}%</div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }
}
