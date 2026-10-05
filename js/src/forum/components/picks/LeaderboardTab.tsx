import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type PicksState from './PicksState';
import PicksSkeleton, { measure } from '../PicksSkeleton';
import ContestSwitch from './ContestSwitch';
import extractText from 'flarum/common/utils/extractText';

interface TabAttrs extends ComponentAttrs {
  state: PicksState;
}

const t = (key: string, params?: Record<string, unknown>) =>
  app.translator.trans('ernestdefoe-picks.forum.leaderboard.' + key, params as any);

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
      { key: 'alltime', label: t('all_time') },
    ];

    // During off-season retention, label the scope buttons to clarify they show final standings
    const scopeLabel = (key: string) => {
      if (isOffSeason && !retentionExpired && key !== 'alltime') {
        return key === 'week'
          ? t('final_week')
          : (lastSeasonName ?? app.translator.trans('ernestdefoe-picks.lib.common.season'));
      }
      return key === 'week'
        ? app.translator.trans('ernestdefoe-picks.lib.common.week')
        : key === 'season'
          ? app.translator.trans('ernestdefoe-picks.lib.common.season')
          : t('all_time');
    };

    const seasonName = lastSeasonName ?? extractText(app.translator.trans('ernestdefoe-picks.lib.common.season'));

    const emptyMessage = (): Mithril.Children => {
      if (noSchedule) {
        return [
          app.translator.trans('ernestdefoe-picks.lib.messages.no_schedule'),
          ' ',
          app.translator.trans('ernestdefoe-picks.lib.messages.check_back'),
        ];
      }
      if (isOffSeason && retentionExpired) {
        return t('season_ended', { season: seasonName });
      }
      if (isOffSeason && !retentionExpired && daysSinceEnded !== null) {
        return t('final_standings', { season: seasonName, days: daysSinceEnded });
      }
      return app.translator.trans('ernestdefoe-picks.lib.messages.no_data');
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
            <i className="fas fa-flag-checkered" /> {t('season_complete')}
            {daysSinceEnded !== null && [' · ', t('days_ago', { days: daysSinceEnded })]}
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
              <div className="PicksLeaderboard-right">{t('col_points')}</div>
              <div className="PicksLeaderboard-right">{t('col_record')}</div>
              <div className="PicksLeaderboard-right">{t('col_accuracy')}</div>
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
   * The Confidence standings: the same table — week, season and all time —
   * with the tiebreaker shown on the week, since that is what separates equal
   * points there.
   */
  private confidence(): Mithril.Children {
    const state = this.attrs.state;
    const c10 = state.c10;
    const scope = state.lbScope === 'season' || state.lbScope === 'alltime' ? state.lbScope : 'week';
    const week = scope === 'week';
    const t = (key: string) => app.translator.trans('ernestdefoe-picks.forum.confidence.' + key);

    return (
      <div className="PicksTab">
        {ContestSwitch(state.lbContest, (c) => {
          state.lbContest = c;
          state.loadLeaderboard();
        })}
        <div className="PicksLbScopes">
          {(['week', 'season', 'alltime'] as const).map((key) => (
            <button
              key={key}
              className={`PicksLbScope ${key === scope ? 'PicksLbScope--active' : ''}`}
              onclick={() => {
                state.lbScope = key;
                state.loadLeaderboard();
              }}
            >
              {key === 'alltime' ? app.translator.trans('ernestdefoe-picks.forum.leaderboard.all_time') : app.translator.trans('ernestdefoe-picks.lib.common.' + key)}
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
