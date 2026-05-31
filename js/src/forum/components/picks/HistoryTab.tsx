import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class HistoryTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;

    return (
      <div className="PicksTab">
        {state.lbHistoryLoading && <LoadingIndicator />}

        {!state.lbHistoryLoading && state.lbHistory.length === 0 && (
          <div className="PicksEmpty">
            No completed seasons yet. History will appear here after the first season ends.
          </div>
        )}

        {!state.lbHistoryLoading && state.lbHistory.length > 0 && (
          <div className="PicksHistory-stack">
            {state.lbHistory.map((season) => {
              const isExpanded = state.lbHistoryExpandedSeasons.has(season.season_id);

              return (
                <div className="PicksHistory-season" key={String(season.season_id)}>
                  <div
                    className="PicksHistory-seasonHeader"
                    onclick={() => {
                      if (isExpanded) {
                        state.lbHistoryExpandedSeasons.delete(season.season_id);
                      } else {
                        state.lbHistoryExpandedSeasons.add(season.season_id);
                      }
                      m.redraw();
                    }}
                  >
                    <div className="PicksHistory-seasonLeft">
                      <span className="PicksHistory-yearBadge">{season.year}</span>
                      <div className="PicksHistory-seasonName">{season.name}</div>
                      <div className="PicksHistory-seasonCount">{season.standings.length} players</div>
                    </div>
                    <div className="PicksHistory-seasonRight">
                      {season.standings.length > 0 && (
                        <span className="PicksHistory-winner">🥇 {season.standings[0].display_name}</span>
                      )}
                      <span className={`PicksHistory-chevron ${isExpanded ? 'PicksHistory-chevron--open' : ''}`}>
                        &#8964;
                      </span>
                    </div>
                  </div>

                  {isExpanded && (
                    <div className="PicksHistory-seasonBody">
                      {season.standings.length === 0 ? (
                        <div className="PicksEmpty" style="padding: 1rem;">
                          No standings recorded for this season.
                        </div>
                      ) : (
                        <div className="PicksLeaderboard">
                          <div className="PicksLeaderboard-head">
                            <div>#</div>
                            <div>{app.translator.trans('ernestdefoe-picks.lib.common.team')}</div>
                            <div className="PicksLeaderboard-right">Pts</div>
                            <div className="PicksLeaderboard-right">W–L</div>
                            <div className="PicksLeaderboard-right">Acc</div>
                          </div>
                          {season.standings.map((entry) => (
                            <div
                              className={`PicksLeaderboard-row
                                ${entry.rank === 1 ? 'PicksLeaderboard-row--gold' : ''}
                                ${entry.rank === 2 ? 'PicksLeaderboard-row--silver' : ''}
                                ${entry.rank === 3 ? 'PicksLeaderboard-row--bronze' : ''}
                              `}
                              key={String(entry.user_id)}
                            >
                              <div
                                className={`PicksLeaderboard-rank ${entry.rank === 1 ? 'PicksLeaderboard-rank--gold' : ''}`}
                              >
                                {entry.rank === 1
                                  ? '🥇'
                                  : entry.rank === 2
                                    ? '🥈'
                                    : entry.rank === 3
                                      ? '🥉'
                                      : entry.rank}
                              </div>
                              <div className="PicksLeaderboard-user">
                                {entry.avatar_url ? (
                                  <img src={entry.avatar_url} alt={entry.display_name} className="PicksAvatar" />
                                ) : (
                                  <div className="PicksAvatar PicksAvatar--initials">
                                    {(entry.display_name || '?').charAt(0)}
                                  </div>
                                )}
                                <span>{entry.display_name}</span>
                              </div>
                              <div className="PicksLeaderboard-right PicksLeaderboard-pts">{entry.total_points}</div>
                              <div className="PicksLeaderboard-right PicksLeaderboard-wl">
                                {entry.correct_picks}–{entry.total_picks - entry.correct_picks}
                              </div>
                              <div className="PicksLeaderboard-right PicksLeaderboard-acc">
                                {entry.accuracy.toFixed(0)}%
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
          </div>
        )}
      </div>
    );
  }
}
