/**
 * Games from the picks-events JSON:API index, in the flat shape the board and
 * the admin games list are drawn from.
 */

type Ref = { type: string; id: string };

export interface ApiResource {
  type: string;
  id: string;
  attributes: Record<string, any>;
  relationships?: Record<string, { data: Ref | null }>;
}

export interface ApiDocument {
  data: ApiResource[];
  included?: ApiResource[];
  meta?: { total?: number };
}

export interface ApiTeam {
  id: number;
  name: string;
  abbreviation: string | null;
  conference: string | null;
  logo_path: string | null;
  logo_url: string | null;
  logo_dark_url: string | null;
}

export interface ApiGame {
  id: number;
  cfbd_id: number | null;
  week_id: number | null;
  week_name: string | null;
  week_open: boolean;
  status: string;
  can_pick: boolean;
  match_date: string | null;
  time_tbd: boolean;
  cutoff_date: string | null;
  neutral_site: boolean;
  home_score: number | null;
  away_score: number | null;
  result: string | null;
  home_rank: number | null;
  away_rank: number | null;
  home_team: ApiTeam | null;
  away_team: ApiTeam | null;
}

function team(res: ApiResource | undefined): ApiTeam | null {
  if (!res) return null;
  const a = res.attributes;

  return {
    id: Number(res.id),
    name: a.name,
    abbreviation: a.abbreviation ?? null,
    conference: a.conference ?? null,
    logo_path: a.logoPath ?? null,
    logo_url: a.logoUrl ?? null,
    logo_dark_url: a.logoDarkUrl ?? null,
  };
}

export function apiGames(doc: ApiDocument): ApiGame[] {
  const included = new Map<string, ApiResource>();
  (doc.included || []).forEach((r) => included.set(r.type + ':' + r.id, r));

  const related = (res: ApiResource, name: string): ApiResource | undefined => {
    const ref = res.relationships?.[name]?.data;
    return ref ? included.get(ref.type + ':' + ref.id) : undefined;
  };

  return doc.data.map((res) => {
    const a = res.attributes;
    const week = related(res, 'week');

    return {
      id: Number(res.id),
      cfbd_id: a.cfbdId ?? null,
      week_id: a.weekId ?? null,
      week_name: week?.attributes.name ?? null,
      week_open: !!week?.attributes.isOpen,
      status: a.status,
      can_pick: !!a.canPick,
      match_date: a.matchDate ?? null,
      time_tbd: !!a.timeTbd,
      cutoff_date: a.cutoffDate ?? null,
      neutral_site: !!a.neutralSite,
      home_score: a.homeScore ?? null,
      away_score: a.awayScore ?? null,
      result: a.result ?? null,
      home_rank: a.homeRank || null,
      away_rank: a.awayRank || null,
      home_team: team(related(res, 'homeTeam')),
      away_team: team(related(res, 'awayTeam')),
    };
  });
}
