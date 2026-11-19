// ── Interfaces ────────────────────────────────────────────────────────────────

export interface ScopeStats {
  total_picks: number;
  correct_picks: number;
  total_points: number;
  accuracy: number;
  rank: number | null;
  total_players: number;
}

export interface UserScores {
  current_week_name: string | null;
  alltime: ScopeStats | null;
  season: ScopeStats | null;
  week: ScopeStats | null;
}

export interface BestWeek {
  week_name: string;
  season_year: number;
  accuracy: number;
  correct_picks: number;
  total_picks: number;
  total_points: number;
}

export interface AlltimeWithExtras extends ScopeStats {
  longest_streak: number;
  best_week: BestWeek | null;
}

export interface WeekHistory {
  week_id: number;
  week_name: string;
  week_number: number;
  is_current: boolean;
  total_picks: number;
  correct_picks: number;
  total_points: number;
  /** Null until a pick that week is decided. */
  accuracy: number | null;
  rank: number | null;
}

export interface SeasonHistory {
  season_id: number;
  name: string;
  year: number;
  is_current: boolean;
  stats: ScopeStats | null;
  weeks: WeekHistory[];
}

export interface UserHistory {
  alltime: AlltimeWithExtras | null;
  seasons: SeasonHistory[];
}

/** One scored Confidence row: a week, a season, or all time. */
export interface C10Line {
  total_points: number;
  total_picks: number;
  correct_picks: number;
  accuracy: number;
  rank: number | null;
  total_players: number;
  tiebreak_diff?: number | null;
}

export interface C10Week extends C10Line {
  week_id: number;
  week_name: string;
  is_current: boolean;
}

export interface C10Season {
  season_id: number;
  name: string;
  year: number;
  is_current: boolean;
  stats: C10Line;
  weeks: C10Week[];
}

export interface C10History {
  enabled: boolean;
  alltime: C10Line | null;
  seasons: C10Season[];
}
