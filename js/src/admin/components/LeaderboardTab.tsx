import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import Week from '../../common/models/Week';

interface LeaderboardEntry {
  rank: number;
  previous_rank: number | null;
  movement: number | null;
  user_id: number;
  username: string;
  display_name: string;
  avatar_url: string | null;
  total_points: number;
  total_picks: number;
  correct_picks: number;
  accuracy: number;
  is_me: boolean;
}

export default class LeaderboardTab extends Component {
  private entries: LeaderboardEntry[] = [];
  private loading: boolean = false;
  private scope: string = 'week';
  private selectedWeekId: string = '';
  private seasonId: string = '';
  private error: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const weeks = app.store.all<Week>('picks-weeks');
    if (weeks.length > 0) {
      this.initFromWeeks();
      this.load();
    } else {
      app.store.find<Week[]>('picks-weeks').then(() => {
        this.initFromWeeks();
        this.load();
      }).catch(() => {
        this.error = 'Failed to load weeks.';
        m.redraw();
      });
    }
  }

  private initFromWeeks() {
    const sorted = this.sortedWeeks();
    if (sorted.length > 0) {
      this.selectedWeekId = String(sorted[0].id());
      this.seasonId = String(sorted[0].seasonId?.() ?? '');
    }
  }

  private load() {
    // Don't attempt to load if there are no weeks
    if (this.scope === 'week' && !this.selectedWeekId) {
      this.loading = false;
      m.redraw();
      return;
    }
    if (this.scope === 'season' && !this.seasonId) {
      this.loading = false;
      m.redraw();
      return;
    }
    this.loading = true;
    this.error = null;
    m.redraw();

    const params: Record<string, any> = { scope: this.scope, limit: 50 };
    if (this.scope === 'week')   params.week_id   = this.selectedWeekId;
    if (this.scope === 'season') params.season_id = this.seasonId;

    app.request<{ data: LeaderboardEntry[]; meta: any }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/picks/leaderboard',
      params,
    }).then((r) => {
      this.entries = r.data || [];
      this.loading = false;
      m.redraw();
    }).catch(() => {
      this.error = 'Failed to load leaderboard.';
      this.loading = false;
      m.redraw();
    });
  }

  private rankMedal(rank: number): Mithril.Children {
    if (rank === 1) return <span className="PicksRankMedal PicksRankMedal--gold">🥇</span>;
    if (rank === 2) return <span className="PicksRankMedal PicksRankMedal--silver">🥈</span>;
    if (rank === 3) return <span className="PicksRankMedal PicksRankMedal--bronze">🥉</span>;
    return <span className="PicksRankNum">{rank}</span>;
  }

  private movement(entry: LeaderboardEntry): Mithril.Children {
    if (entry.movement === null || entry.movement === 0) return null;
    const up = entry.movement > 0;
    return (
      <span className={`PicksMovementBadge ${up ? 'PicksMovementBadge--up' : 'PicksMovementBadge--down'}`}>
        {up ? `↑${entry.movement}` : `↓${Math.abs(entry.movement)}`}
      </span>
    );
  }

  private sortedWeeks(): Week[] {
    return app.store.all<Week>('picks-weeks').sort((a, b) => {
      if (a.seasonType() !== b.seasonType()) return a.seasonType() === 'regular' ? -1 : 1;
      return (a.weekNumber() || 0) - (b.weekNumber() || 0);
    });
  }

  view() {
    const weeks = this.sortedWeeks();

    return (
      <div className="PicksLeaderboardTab">
        <div className="PicksTab-header">
          <div>
            <h3>
              <i className="fas fa-trophy" />
              {' '}{app.translator.trans('ernestdefoe-picks.admin.nav.scores')}
            </h3>
          </div>
        </div>

        <div className="PicksTab-filters">
          <select
            className="FormControl"
            value={this.scope}
            onchange={(e: Event) => {
              this.scope = (e.target as HTMLSelectElement).value;
              this.load();
            }}
          >
            <option value="week">Week</option>
            <option value="season">Season</option>
            <option value="alltime">All Time</option>
          </select>

          {this.scope === 'week' && (
            <select
              className="FormControl"
              value={this.selectedWeekId}
              onchange={(e: Event) => {
                this.selectedWeekId = (e.target as HTMLSelectElement).value;
                this.load();
              }}
            >
              {weeks.map(w => (
                <option key={String(w.id())} value={String(w.id())}>{w.name()}</option>
              ))}
            </select>
          )}
        </div>

        {this.error && <div className="PicksAlert PicksAlert--error">{this.error}</div>}

        {this.loading ? (
          <LoadingIndicator />
        ) : this.entries.length === 0 ? (
          <div className="PicksEmptyState">
            {!this.selectedWeekId && this.scope === 'week'
              ? 'No schedule synced yet. Sync a schedule from Seasons & Weeks to see the leaderboard.'
              : 'No scores yet for this period.'}
          </div>
        ) : (
          <div className="PicksAdminLeaderboard">
            <div className="PicksAdminLeaderboard-head">
              <div>#</div>
              <div>Player</div>
              <div className="PicksAdminLeaderboard-right">Pts</div>
              <div className="PicksAdminLeaderboard-right">W</div>
              <div className="PicksAdminLeaderboard-right">L</div>
              <div className="PicksAdminLeaderboard-right">Picks</div>
              <div className="PicksAdminLeaderboard-right">Acc</div>
            </div>

            {this.entries.map((entry) => (
              <div
                key={String(entry.user_id)}
                className={`PicksAdminLeaderboard-row
                  ${entry.rank === 1 ? 'PicksAdminLeaderboard-row--gold' : ''}
                  ${entry.rank === 2 ? 'PicksAdminLeaderboard-row--silver' : ''}
                  ${entry.rank === 3 ? 'PicksAdminLeaderboard-row--bronze' : ''}
                `}
              >
                <div className="PicksAdminLeaderboard-rank">
                  {this.rankMedal(entry.rank)}
                </div>
                <div className="PicksAdminLeaderboard-user">
                  {entry.avatar_url
                    ? <img src={entry.avatar_url} alt={entry.display_name} className="PicksAdminAvatar" />
                    : <div className="PicksAdminAvatar PicksAdminAvatar--initials">{(entry.display_name || '?').charAt(0)}</div>
                  }
                  <span className="PicksAdminLeaderboard-name">{entry.display_name}</span>
                  {this.movement(entry)}
                </div>
                <div className="PicksAdminLeaderboard-right PicksAdminLeaderboard-pts">{entry.total_points}</div>
                <div className="PicksAdminLeaderboard-right">{entry.correct_picks}</div>
                <div className="PicksAdminLeaderboard-right">{entry.total_picks - entry.correct_picks}</div>
                <div className="PicksAdminLeaderboard-right">{entry.total_picks}</div>
                <div className="PicksAdminLeaderboard-right">{entry.accuracy.toFixed(1)}%</div>
              </div>
            ))}
          </div>
        )}
      </div>
    );
  }
}
