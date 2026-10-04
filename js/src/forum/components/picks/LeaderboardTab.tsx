import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';
import PicksSkeleton, { measure } from '../PicksSkeleton';
import ContestSwitch from './ContestSwitch';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

export default class LeaderboardTab extends Component<TabAttrs> {
  view(): Mithril.Children {
    const state = this.attrs.state;

    if (state.lbContest === 'c10') return this.confidence();

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
        {ContestSwitch(state.lbContest, (c) => {
          state.lbContest = c;
          state.loadLeaderboard();
        })}
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
          <PicksSkeleton surface="leaderboard" fallback={318} rows={6} variant="table" />
        ) : state.leaderboard.length === 0 ? (
          <div className="PicksEmpty">{emptyMessage()}</div>
        ) : (
          <div className="PicksLeaderboard" {...measure('leaderboard')}>
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

  /**
   * The Confidence standings: the same table, week and season only, with the
   * tiebreaker shown on the week, since that is what separates equal points.
   */
  private confidence(): Mithril.Children {
    const state = this.attrs.state;
    const c10 = state.c10;
    const week = state.lbScope !== 'season';
    const t = (key: string) => app.translator.trans('ernestdefoe-picks.forum.confidence.' + key);

    return (
      <div className="PicksTab">
        {ContestSwitch(state.lbContest, (c) => {
          state.lbContest = c;
          state.loadLeaderboard();
        })}
        <div className="PicksLbScopes">
          {(['week', 'season'] as const).map((key) => (
            <button
              key={key}
              className={`PicksLbScope ${(key === 'week') === week ? 'PicksLbScope--active' : ''}`}
              onclick={() => {
                state.lbScope = key;
                state.loadLeaderboard();
              }}
            >
              {app.translator.trans('ernestdefoe-picks.lib.common.' + key)}
            </button>
          ))}
        </div>

        {c10.lbLoading ? (
          <PicksSkeleton surface="c10-leaderboard" fallback={318} rows={6} variant="table" />
        ) : c10.leaderboard.length === 0 ? (
          <div className="PicksEmpty">{t('no_standings')}</div>
        ) : (
          <div className={`PicksLeaderboard ${week ? 'PicksLeaderboard--tb' : ''}`} {...measure('c10-leaderboard')}>
            <div className="PicksLeaderboard-head">
              <div>#</div>
              <div>{app.translator.trans('ernestdefoe-picks.lib.common.team')}</div>
              <div className="PicksLeaderboard-right">{t('col_points')}</div>
              <div className="PicksLeaderboard-right">{t('col_record')}</div>
              <div className="PicksLeaderboard-right" title={week ? (t('col_tiebreaker_title') as any) : undefined}>
                {week ? t('col_tiebreaker') : t('col_accuracy')}
              </div>
            </div>
            {c10.leaderboard.map((entry) => (
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
                    <span className={`PicksMovement ${entry.movement > 0 ? 'PicksMovement--up' : 'PicksMovement--down'}`}>
                      {entry.movement > 0 ? `↑${entry.movement}` : `↓${Math.abs(entry.movement)}`}
                    </span>
                  )}
                </div>
                <div className="PicksLeaderboard-right PicksLeaderboard-pts">{entry.total_points}</div>
                <div className="PicksLeaderboard-right PicksLeaderboard-wl">
                  {entry.correct_picks}–{entry.total_picks - entry.correct_picks}
                </div>
                <div className="PicksLeaderboard-right PicksLeaderboard-acc">
                  {week
                    ? entry.tiebreak_diff === null || entry.tiebreak_diff === undefined
                      ? '–'
                      : `±${entry.tiebreak_diff}`
                    : `${entry.accuracy.toFixed(0)}%`}
                </div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }
}
