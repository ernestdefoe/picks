import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';

interface GameTeam {
  id: number;
  name: string;
  abbreviation: string | null;
  conference: string | null;
  logo_url: string | null;
  logo_dark_url: string | null;
}

interface MyPick {
  id: number;
  selected_outcome: 'home' | 'away';
  is_correct: boolean | null;
  confidence: number | null;
}

interface Game {
  id: number;
  status: string;
  can_pick: boolean;
  match_date: string | null;
  cutoff_date: string | null;
  neutral_site: boolean;
  home_score: number | null;
  away_score: number | null;
  result: string | null;
  home_team: GameTeam | null;
  away_team: GameTeam | null;
  my_pick: MyPick | null;
}

interface WeekInfo {
  id: number;
  name: string;
  week_number: number | null;
  season_type: string;
  start_date: string | null;
  end_date: string | null;
  is_open: boolean;
}

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

// ── History tab interfaces ─────────────────────────────────────────────────────

interface LeaderboardHistoryEntry {
  rank: number;
  user_id: number;
  username: string;
  display_name: string;
  avatar_url: string | null;
  total_picks: number;
  correct_picks: number;
  total_points: number;
  accuracy: number;
}

interface LeaderboardHistorySeason {
  season_id: number;
  name: string;
  year: number;
  standings: LeaderboardHistoryEntry[];
}


export default class PicksPage extends Page {
  private activeTab: string = 'matches';
  private weeks: WeekInfo[] = [];
  private weeksLoaded: boolean = false;
  private currentWeekId: number | null = null;
  private weekOpen: boolean = false;
  private games: Game[] = [];
  private gamesLoading: boolean = false;
  private submitting: Record<number, boolean> = {};
  private leaderboard: LeaderboardEntry[] = [];
  private lbLoading: boolean = false;
  private lbScope: string = 'week';
  private seasonId: number | null = null;
  private weeksMeta: { total_picks?: number; picked?: number } = {};

  // ── History tab state ────────────────────────────────────────────────────
  private lbHistory: LeaderboardHistorySeason[] = [];
  private lbHistoryLoading: boolean = false;
  private lbHistoryExpandedSeasons: Set<number> = new Set();

  // ── Off-season / retention state ─────────────────────────────────────────
  private lbContext: {
    is_active: boolean;
    is_off_season: boolean;
    retention_expired: boolean;
    days_since_ended: number | null;
    last_week_id: number | null;
    last_season_id: number | null;
    last_season_name: string | null;
  } | null = null;
  private lbContextLoading: boolean = false;


  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    app.setTitle(app.forum.attribute('picksNavLabel') || app.translator.trans('ernestdefoe-picks.lib.nav.picks'));

    const weekIdParam = parseInt(m.route.param('weekId'));

    // Load weeks first, then load games for the current/selected week
    app.store.find<any[]>('picks-weeks').then((weeks: any[]) => {
      this.weeks = weeks
        .map((w: any) => ({
          id: parseInt(String(w.id())),
          name: w.name(),
          week_number: w.weekNumber(),
          season_type: w.seasonType(),
          start_date: w.startDate(),
          end_date: w.endDate(),
          is_open: w.isOpen() ?? false,
        }))
        .sort((a, b) => {
          if (a.season_type !== b.season_type) return a.season_type === 'regular' ? -1 : 1;
          return (a.week_number || 0) - (b.week_number || 0);
        });

      // Also grab season_id from the first week's season relationship
      const firstWeek = app.store.all<any>('picks-weeks')[0];
      if (firstWeek) {
        this.seasonId = firstWeek.seasonId?.() ?? null;
      }

      if (weekIdParam && this.weeks.find(w => w.id === weekIdParam)) {
        this.currentWeekId = weekIdParam;
      } else {
        // Default to the last open week (highest week number that is open)
        // This handles the case where multiple weeks are open after auto-unlock
        const openWeeks = this.weeks.filter(w => w.is_open);
        if (openWeeks.length > 0) {
          this.currentWeekId = openWeeks[openWeeks.length - 1].id;
        } else if (this.weeks.length > 0) {
          this.currentWeekId = this.weeks[this.weeks.length - 1].id;
        }
      }

      if (this.currentWeekId) {
        this.loadGames();
      }

      // Always redraw even if empty — shows the no-schedule message
      this.weeksLoaded = true;
      m.redraw();
    }).catch(() => {
      this.weeksLoaded = true;
      m.redraw();
    });
  }

  private loadGames() {
    if (!this.currentWeekId) return;

    this.gamesLoading = true;
    m.redraw();

    app.request<{ data: Game[]; meta: any }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/picks/my-picks',
      params: { week_id: this.currentWeekId },
    }).then((r) => {
      this.games = r.data || [];
      this.weeksMeta = r.meta || {};
      this.weekOpen = r.meta?.week_open ?? false;
      this.gamesLoading = false;
      m.redraw();
    }).catch(() => {
      this.gamesLoading = false;
      m.redraw();
    });
  }

  private loadLeaderboard() {
    const isActive = !!this.currentWeekId || !!this.seasonId;

    // If no active week/season, fetch context first to check off-season retention
    if (!isActive && !this.lbContext && !this.lbContextLoading) {
      this.loadLeaderboardContext(() => {
        this.loadLeaderboard();
      });
      return;
    }

    // Resolve which IDs to use — active season or off-season retained IDs
    const weekId   = this.currentWeekId   ?? this.lbContext?.last_week_id   ?? null;
    const seasonId = this.seasonId        ?? this.lbContext?.last_season_id ?? null;
    const isOffSeason   = this.lbContext?.is_off_season      ?? false;
    const retentionExpired = this.lbContext?.retention_expired ?? false;

    // Week/season scopes with no IDs and retention expired — show off-season state
    if ((this.lbScope === 'week' || this.lbScope === 'season') && isOffSeason && retentionExpired) {
      this.leaderboard = [];
      this.lbLoading   = false;
      m.redraw();
      return;
    }

    // Week/season scopes with no IDs and no off-season data — no schedule yet
    if (this.lbScope === 'week' && !weekId) {
      this.leaderboard = [];
      this.lbLoading   = false;
      m.redraw();
      return;
    }
    if (this.lbScope === 'season' && !seasonId) {
      this.leaderboard = [];
      this.lbLoading   = false;
      m.redraw();
      return;
    }

    this.lbLoading = true;
    m.redraw();

    const params: Record<string, any> = { scope: this.lbScope, limit: 25 };
    if (this.lbScope === 'week'   && weekId)   params.week_id   = weekId;
    if (this.lbScope === 'season' && seasonId) params.season_id = seasonId;

    app.request<{ data: LeaderboardEntry[]; meta: any }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/picks/leaderboard',
      params,
    }).then((r) => {
      this.leaderboard = r.data || [];
      this.lbLoading   = false;
      m.redraw();
    }).catch(() => {
      this.lbLoading   = false;
      m.redraw();
    });
  }

  private submitPick(game: Game, outcome: 'home' | 'away') {
    if (!app.session.user) {
      m.route.set(app.route('login'));
      return;
    }
    if (!game.can_pick) return;
    if (this.submitting[game.id]) return;

    // Clicking the already-selected team removes the pick
    if (game.my_pick?.selected_outcome === outcome) {
      this.deletePick(game);
      return;
    }

    const prev = game.my_pick ? game.my_pick.selected_outcome : null;
    if (!game.my_pick) {
      game.my_pick = { id: 0, selected_outcome: outcome, is_correct: null, confidence: null };
    } else {
      game.my_pick.selected_outcome = outcome;
    }
    this.submitting[game.id] = true;
    m.redraw();

    app.request<{ status: string; pick_id: number; selected_outcome: string; confidence: number | null }>({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/picks/submit',
      body: { event_id: game.id, selected_outcome: outcome },
    }).then((r) => {
      if (game.my_pick) game.my_pick.id = r.pick_id;
      // Increment picked count only when this is a new pick, not changing an existing one
      if (prev === null) {
        this.weeksMeta.picked = (this.weeksMeta.picked || 0) + 1;
      }
      this.submitting[game.id] = false;
      m.redraw();
    }).catch(() => {
      if (game.my_pick) game.my_pick.selected_outcome = prev as 'home' | 'away';
      this.submitting[game.id] = false;
      m.redraw();
    });
  }

  private deletePick(game: Game) {
    if (!game.my_pick || this.submitting[game.id]) return;

    // Optimistic update
    const prevPick = game.my_pick;
    game.my_pick = null;
    this.submitting[game.id] = true;
    m.redraw();

    app.request({
      method: 'DELETE',
      url: `${app.forum.attribute('apiUrl')}/picks/events/${game.id}/pick`,
    }).then(() => {
      this.submitting[game.id] = false;
      // Update the week meta picked count
      if (this.weeksMeta.picked && this.weeksMeta.picked > 0) {
        this.weeksMeta.picked--;
      }
      m.redraw();
    }).catch(() => {
      // Revert on failure
      game.my_pick = prevPick;
      this.submitting[game.id] = false;
      m.redraw();
    });
  }

  private submitConfidence(game: Game, confidence: number) {
    if (!game.my_pick || !game.can_pick) return;
    game.my_pick.confidence = confidence;
    m.redraw();

    app.request({
      method: 'POST',
      url: app.forum.attribute('apiUrl') + '/picks/submit',
      body: {
        event_id: game.id,
        selected_outcome: game.my_pick.selected_outcome,
        confidence,
      },
    }).catch(() => m.redraw());
  }

  private currentWeek(): WeekInfo | undefined {
    return this.weeks.find(w => w.id === this.currentWeekId);
  }

  private prevWeek() {
    const idx = this.weeks.findIndex(w => w.id === this.currentWeekId);
    if (idx > 0) {
      this.currentWeekId = this.weeks[idx - 1].id;
      this.loadGames();
    }
  }

  private nextWeek() {
    const idx = this.weeks.findIndex(w => w.id === this.currentWeekId);
    if (idx < this.weeks.length - 1) {
      this.currentWeekId = this.weeks[idx + 1].id;
      this.loadGames();
    }
  }

  private formatDate(dateStr: string | null): string {
    if (!dateStr) return '';
    try {
      return new Date(dateStr).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
    } catch {
      return dateStr;
    }
  }

  private renderTeamButton(game: Game, side: 'home' | 'away'): Mithril.Children {
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

    const logoUrl     = team?.logo_url;
    const logoDarkUrl = team?.logo_dark_url || logoUrl;

    return (
      <button
        className={cls}
        disabled={(!game.can_pick || this.submitting[game.id]) || undefined}
        onclick={() => game.can_pick && this.submitPick(game, side)}
      >
        <div className="PicksTeamBtn-logo">
          {logoUrl ? (
            <>
              <img src={logoUrl}     alt={team?.name || ''} className="PicksTeamBtn-logo-light" />
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
    const isFinished = game.status === 'finished';
    const isPending = game.my_pick && game.my_pick.is_correct === null && isFinished;
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
            {isFinished && game.home_score !== null
              ? <span className="PicksGameCard-score">{game.home_score}–{game.away_score}</span>
              : <span>vs</span>
            }
          </div>

          {this.renderTeamButton(game, 'away')}
        </div>

        {(game.my_pick || (!game.can_pick && game.status === 'scheduled')) && (
          <div className="PicksGameCard-result">
            {isCorrect && <span className="PicksTag PicksTag--correct">✓ Correct · +{game.my_pick?.confidence ?? 1} pt{(game.my_pick?.confidence ?? 1) !== 1 ? 's' : ''}</span>}
            {isIncorrect && <span className="PicksTag PicksTag--incorrect">✗ Incorrect</span>}
            {game.my_pick && !isFinished && <span className="PicksTag PicksTag--pending">Pick saved · awaiting result</span>}
            {!game.can_pick && game.status === 'scheduled' && !game.my_pick && <span className="PicksTag PicksTag--locked">Cutoff passed · no pick</span>}
          </div>
        )}

        {/* Confidence selector — shown when mode is on, pick is made, game is open */}
        {app.forum.attribute('picksConfidenceMode') && game.my_pick && game.can_pick && !isFinished && (
          <div className="PicksConfidence">
            <span className="PicksConfidence-label">Confidence:</span>
            <div className="PicksConfidence-buttons">
              {[1,2,3,4,5,6,7,8,9,10].map(n => (
                <button
                  key={n}
                  className={`PicksConfidence-btn ${game.my_pick?.confidence === n ? 'PicksConfidence-btn--active' : ''}`}
                  onclick={() => this.submitConfidence(game, n)}
                >
                  {n}
                </button>
              ))}
            </div>
            {app.forum.attribute('picksConfidencePenalty') !== 'none' && (
              <span className="PicksConfidence-hint">
                {app.forum.attribute('picksConfidencePenalty') === 'full'
                  ? '±pts'
                  : '−½pts if wrong'}
              </span>
            )}
          </div>
        )}
      </div>
    );
  }

  private renderMatchesTab(): Mithril.Children {
    const week = this.currentWeek();
    const idx = this.weeks.findIndex(w => w.id === this.currentWeekId);
    const picked = this.weeksMeta.picked || 0;
    const total = this.weeksMeta.total || 0;

    return (
      <div className="PicksTab">
        <div className="PicksWeekNav">
          <div>
            <div className="PicksWeekNav-title">{week?.name || '—'}</div>
            {week?.start_date && <div className="PicksWeekNav-dates">{week.start_date} – {week.end_date}</div>}
          </div>
          <div className="PicksWeekNav-arrows">
            <Button className="Button Button--icon" icon="fas fa-chevron-left" disabled={idx <= 0} onclick={() => this.prevWeek()} />
            <Button className="Button Button--icon" icon="fas fa-chevron-right" disabled={idx >= this.weeks.length - 1} onclick={() => this.nextWeek()} />
          </div>
        </div>

        {app.session.user && total > 0 && (
          <div className="PicksStatusBar">
            <span>{app.translator.trans('ernestdefoe-picks.lib.common.picked')}: <strong>{picked} / {total}</strong></span>
          </div>
        )}

        {!this.weekOpen && !this.gamesLoading && this.games.length > 0 && (
          <div className="PicksWeekLocked">
            <i className="fas fa-lock" />
            {' '}Picks for this week are not yet open. Check back soon!
          </div>
        )}

        {this.gamesLoading
          ? <LoadingIndicator />
          : this.games.length === 0
            ? <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.no_matches')}</div>
            : this.games.map(game => this.renderGameCard(game))
        }
      </div>
    );
  }

  private renderMyPicksTab(): Mithril.Children {
    const week = this.currentWeek();
    const idx = this.weeks.findIndex(w => w.id === this.currentWeekId);
    const myGames = this.games.filter(g => g.my_pick);
    const correct = myGames.filter(g => g.my_pick?.is_correct === true).length;
    const incorrect = myGames.filter(g => g.my_pick?.is_correct === false).length;

    return (
      <div className="PicksTab">
        <div className="PicksWeekNav">
          <div>
            <div className="PicksWeekNav-title">{app.translator.trans('ernestdefoe-picks.lib.nav.my_picks')} · {week?.name}</div>
          </div>
          <div className="PicksWeekNav-arrows">
            <Button className="Button Button--icon" icon="fas fa-chevron-left" disabled={idx <= 0} onclick={() => this.prevWeek()} />
            <Button className="Button Button--icon" icon="fas fa-chevron-right" disabled={idx >= this.weeks.length - 1} onclick={() => this.nextWeek()} />
          </div>
        </div>

        {myGames.length > 0 && (
          <div className="PicksStatusBar">
            <span>{app.translator.trans('ernestdefoe-picks.lib.common.picked')}: <strong>{myGames.length}</strong></span>
            <span>✓ <strong>{correct}</strong></span>
            <span>✗ <strong>{incorrect}</strong></span>
            <span>{app.translator.trans('ernestdefoe-picks.lib.common.points')}: <strong>{correct}</strong></span>
          </div>
        )}

        {this.gamesLoading
          ? <LoadingIndicator />
          : myGames.length === 0
            ? <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.no_data')}</div>
            : myGames.map(game => {
                const side = game.my_pick!.selected_outcome;
                const team = side === 'home' ? game.home_team : game.away_team;
                const oppTeam = side === 'home' ? game.away_team : game.home_team;
                const isCorrect = game.my_pick!.is_correct === true;
                const isIncorrect = game.my_pick!.is_correct === false;

                return (
                  <div className={`PicksMyPickRow ${isCorrect ? 'PicksMyPickRow--correct' : isIncorrect ? 'PicksMyPickRow--incorrect' : ''}`} key={String(game.id)}>
                    <div className="PicksMyPickRow-logos">
                      {team?.logo_url && (
                        <>
                          <img src={team.logo_url}                      alt={team.name} className="PicksMyPickRow-logo PicksMyPickRow-logo--light" />
                          <img src={team.logo_dark_url || team.logo_url} alt={team.name} className="PicksMyPickRow-logo PicksMyPickRow-logo--dark" />
                        </>
                      )}
                      <span className="PicksMyPickRow-sep">vs</span>
                      {oppTeam?.logo_url && (
                        <>
                          <img src={oppTeam.logo_url}                         alt={oppTeam.name} className="PicksMyPickRow-logo PicksMyPickRow-logo--light" />
                          <img src={oppTeam.logo_dark_url || oppTeam.logo_url} alt={oppTeam.name} className="PicksMyPickRow-logo PicksMyPickRow-logo--dark" />
                        </>
                      )}
                    </div>
                    <div className="PicksMyPickRow-info">
                      <div className="PicksMyPickRow-matchup">{team?.name} vs {oppTeam?.name}</div>
                      <div className="PicksMyPickRow-pick">
                        {app.translator.trans('ernestdefoe-picks.lib.common.picked')}: <strong>{team?.name}</strong>
                        {game.status === 'finished' && game.home_score !== null && <span> · {game.home_score}–{game.away_score}</span>}
                      </div>
                    </div>
                    <div className="PicksMyPickRow-status">
                      {isCorrect && <span className="PicksTag PicksTag--correct">+{game.my_pick!.confidence ?? 1} pt{(game.my_pick!.confidence ?? 1) !== 1 ? 's' : ''}</span>}
                      {isIncorrect && <span className="PicksTag PicksTag--incorrect">+0 pts</span>}
                      {!isCorrect && !isIncorrect && (
                        <span className="PicksTag PicksTag--pending">
                          Pending{app.forum.attribute('picksConfidenceMode') && game.my_pick!.confidence ? ` · ${game.my_pick!.confidence}` : ''}
                        </span>
                      )}
                    </div>
                  </div>
                );
              })
        }
      </div>
    );
  }

  private renderLeaderboardTab(): Mithril.Children {
    const isOffSeason      = this.lbContext?.is_off_season      ?? false;
    const retentionExpired = this.lbContext?.retention_expired  ?? false;
    const lastSeasonName   = this.lbContext?.last_season_name   ?? null;
    const daysSinceEnded   = this.lbContext?.days_since_ended   ?? null;
    const noSchedule       = !this.currentWeekId && !isOffSeason;

    const scopes = [
      { key: 'week',    label: app.translator.trans('ernestdefoe-picks.lib.common.week') },
      { key: 'season',  label: app.translator.trans('ernestdefoe-picks.lib.common.season') },
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
          {scopes.map(s => (
            <button
              key={s.key}
              className={`PicksLbScope ${this.lbScope === s.key ? 'PicksLbScope--active' : ''}`}
              onclick={() => { this.lbScope = s.key; this.loadLeaderboard(); }}
            >
              {scopeLabel(s.key)}
            </button>
          ))}
        </div>

        {isOffSeason && !retentionExpired && (
          <div className="PicksOffSeasonBanner">
            <i className="fas fa-flag-checkered" />
            {' '}Season complete · Final standings locked
            {daysSinceEnded !== null && ` · ${daysSinceEnded}d ago`}
          </div>
        )}

        {this.lbLoading
          ? <LoadingIndicator />
          : this.leaderboard.length === 0
            ? <div className="PicksEmpty">{emptyMessage()}</div>
            : (
              <div className="PicksLeaderboard">
                <div className="PicksLeaderboard-head">
                  <div>#</div>
                  <div>{app.translator.trans('ernestdefoe-picks.lib.common.team')}</div>
                  <div className="PicksLeaderboard-right">Pts</div>
                  <div className="PicksLeaderboard-right">W–L</div>
                  <div className="PicksLeaderboard-right">Acc</div>
                </div>
                {this.leaderboard.map(entry => (
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
                      {entry.avatar_url
                        ? <img src={entry.avatar_url} alt={entry.display_name} className="PicksAvatar" />
                        : <div className="PicksAvatar PicksAvatar--initials">{(entry.display_name || '?').charAt(0)}</div>
                      }
                      <span>{entry.display_name}</span>
                      {entry.movement !== null && entry.movement !== 0 && (
                        <span className={`PicksMovement ${entry.movement > 0 ? 'PicksMovement--up' : 'PicksMovement--down'}`}>
                          {entry.movement > 0 ? `↑${entry.movement}` : `↓${Math.abs(entry.movement)}`}
                        </span>
                      )}
                    </div>
                    <div className="PicksLeaderboard-right PicksLeaderboard-pts">{entry.total_points}</div>
                    <div className="PicksLeaderboard-right PicksLeaderboard-wl">{entry.correct_picks}–{entry.total_picks - entry.correct_picks}</div>
                    <div className="PicksLeaderboard-right PicksLeaderboard-acc">{entry.accuracy.toFixed(0)}%</div>
                  </div>
                ))}
              </div>
            )
        }
      </div>
    );
  }


  // ── Leaderboard history methods ───────────────────────────────────────────

  private loadLeaderboardContext(onComplete: () => void) {
    if (this.lbContextLoading) return;
    this.lbContextLoading = true;

    app.request<{
      is_active: boolean;
      is_off_season: boolean;
      retention_expired: boolean;
      days_since_ended: number | null;
      last_week_id: number | null;
      last_season_id: number | null;
      last_season_name: string | null;
    }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/picks/leaderboard-context',
    }).then((r) => {
      this.lbContext        = r;
      this.lbContextLoading = false;
      onComplete();
    }).catch(() => {
      this.lbContextLoading = false;
      onComplete();
    });
  }

  private loadLeaderboardHistory() {
    if (this.lbHistoryLoading || this.lbHistory.length > 0) return;
    this.lbHistoryLoading = true;
    m.redraw();

    app.request<{ seasons: LeaderboardHistorySeason[] }>({
      method: 'GET',
      url: app.forum.attribute('apiUrl') + '/picks/leaderboard-history',
    }).then((r) => {
      this.lbHistory        = r.seasons || [];
      this.lbHistoryLoading = false;
      if (this.lbHistory.length > 0) {
        this.lbHistoryExpandedSeasons.add(this.lbHistory[0].season_id);
      }
      m.redraw();
    }).catch(() => {
      this.lbHistoryLoading = false;
      m.redraw();
    });
  }

  private renderHistoryTab(): Mithril.Children {
    return (
      <div className="PicksTab">
        {this.lbHistoryLoading && <LoadingIndicator />}

        {!this.lbHistoryLoading && this.lbHistory.length === 0 && (
          <div className="PicksEmpty">No completed seasons yet. History will appear here after the first season ends.</div>
        )}

        {!this.lbHistoryLoading && this.lbHistory.length > 0 && (
          <div className="PicksHistory-stack">
            {this.lbHistory.map(season => {
              const isExpanded = this.lbHistoryExpandedSeasons.has(season.season_id);

              return (
                <div className="PicksHistory-season" key={String(season.season_id)}>
                  <div
                    className="PicksHistory-seasonHeader"
                    onclick={() => {
                      if (isExpanded) {
                        this.lbHistoryExpandedSeasons.delete(season.season_id);
                      } else {
                        this.lbHistoryExpandedSeasons.add(season.season_id);
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
                        <span className="PicksHistory-winner">
                          🥇 {season.standings[0].display_name}
                        </span>
                      )}
                      <span className={`PicksHistory-chevron ${isExpanded ? 'PicksHistory-chevron--open' : ''}`}>
                        &#8964;
                      </span>
                    </div>
                  </div>

                  {isExpanded && (
                    <div className="PicksHistory-seasonBody">
                      {season.standings.length === 0 ? (
                        <div className="PicksEmpty" style="padding: 1rem;">No standings recorded for this season.</div>
                      ) : (
                        <div className="PicksLeaderboard">
                          <div className="PicksLeaderboard-head">
                            <div>#</div>
                            <div>{app.translator.trans('ernestdefoe-picks.lib.common.team')}</div>
                            <div className="PicksLeaderboard-right">Pts</div>
                            <div className="PicksLeaderboard-right">W–L</div>
                            <div className="PicksLeaderboard-right">Acc</div>
                          </div>
                          {season.standings.map(entry => (
                            <div
                              className={`PicksLeaderboard-row
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
                                {entry.avatar_url
                                  ? <img src={entry.avatar_url} alt={entry.display_name} className="PicksAvatar" />
                                  : <div className="PicksAvatar PicksAvatar--initials">{(entry.display_name || '?').charAt(0)}</div>
                                }
                                <span>{entry.display_name}</span>
                              </div>
                              <div className="PicksLeaderboard-right PicksLeaderboard-pts">{entry.total_points}</div>
                              <div className="PicksLeaderboard-right PicksLeaderboard-wl">{entry.correct_picks}–{entry.total_picks - entry.correct_picks}</div>
                              <div className="PicksLeaderboard-right PicksLeaderboard-acc">{entry.accuracy.toFixed(0)}%</div>
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

  view() {
    const canView = app.forum.attribute('picksCanView') || app.session.user?.isAdmin();

    return (
      <PageStructure className="PicksPage" sidebar={() => <IndexSidebar />}>
        <div className="PicksPage-inner">
          <div className="PicksPage-tabs">
            {[
              { key: 'matches',     label: app.translator.trans('ernestdefoe-picks.lib.nav.matches'),     icon: 'fas fa-football' },
              { key: 'mypicks',     label: app.translator.trans('ernestdefoe-picks.lib.nav.my_picks'),    icon: 'fas fa-check-circle' },
              { key: 'leaderboard', label: app.translator.trans('ernestdefoe-picks.lib.nav.leaderboard'), icon: 'fas fa-trophy' },
              { key: 'history',     label: app.translator.trans('ernestdefoe-picks.lib.nav.history'),     icon: 'fas fa-history' },
            ].map(tab => (
              <button
                key={tab.key}
                className={`PicksPage-tab ${this.activeTab === tab.key ? 'PicksPage-tab--active' : ''}`}
                onclick={() => {
                  this.activeTab = tab.key;
                  if (tab.key === 'leaderboard' && this.leaderboard.length === 0) {
                    this.loadLeaderboard();
                  }
                  if (tab.key === 'history') {
                    this.loadLeaderboardHistory();
                  }
                  m.redraw();
                }}
              >
                <i className={tab.icon} />
                {' '}{tab.label}
              </button>
            ))}
          </div>

          {!canView ? (
            <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.login_required')}</div>
          ) : !this.weeksLoaded ? (
            <LoadingIndicator />
          ) : this.weeks.length === 0 && this.activeTab !== 'history' ? (
            <div className="PicksEmpty PicksEmpty--noSchedule">
              <i className="fas fa-football" />
              <p>No schedule has been imported yet.</p>
              <p>Check back soon!</p>
            </div>
          ) : (
            <>
              {this.activeTab === 'matches'     && this.renderMatchesTab()}
              {this.activeTab === 'mypicks'     && this.renderMyPicksTab()}
              {this.activeTab === 'leaderboard' && this.renderLeaderboardTab()}
              {this.activeTab === 'history'     && this.renderHistoryTab()}
            </>
          )}
        </div>
      </PageStructure>
    );
  }
}
