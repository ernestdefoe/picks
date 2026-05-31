export interface GameTeam {
  id: number;
  name: string;
  abbreviation: string | null;
  conference: string | null;
  logo_url: string | null;
  logo_dark_url: string | null;
}

export interface MyPick {
  id: number;
  selected_outcome: 'home' | 'away';
  is_correct: boolean | null;
  confidence: number | null;
}

export interface Game {
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

export interface WeekInfo {
  id: number;
  name: string;
  week_number: number | null;
  season_type: string;
  start_date: string | null;
  end_date: string | null;
  is_open: boolean;
}

export interface WeeksMeta {
  total?: number;
  picked?: number;
  total_picks?: number;
  week_open?: boolean;
}

export interface LeaderboardEntry {
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

export interface LeaderboardHistoryEntry {
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

export interface LeaderboardHistorySeason {
  season_id: number;
  name: string;
  year: number;
  standings: LeaderboardHistoryEntry[];
}

export interface LeaderboardContext {
  is_active: boolean;
  is_off_season: boolean;
  retention_expired: boolean;
  days_since_ended: number | null;
  last_week_id: number | null;
  last_season_id: number | null;
  last_season_name: string | null;
}
