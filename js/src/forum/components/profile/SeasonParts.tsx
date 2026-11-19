import type Mithril from 'mithril';
import { tp } from './helpers';
import type { ScopeStats } from './types';

/** The pieces both season cards share — the regular board's and the Confidence contest's. */

interface SeasonHead {
  name: string;
  year: number;
  is_current: boolean;
  weeks: unknown[];
}

/** The year badge, the season's name and its "N weeks · N picks" line. */
export function seasonLeft(season: SeasonHead, picks: number): Mithril.Children {
  return (
    <div className="Picks-season-left">
      <span className={`Picks-season-badge ${season.is_current ? 'Picks-season-badge--current' : 'Picks-season-badge--past'}`}>{season.year}</span>
      <div>
        <div className="Picks-season-title">
          {season.name}
          {season.is_current && <span className="Picks-season-openBadge">{tp('in_progress')}</span>}
        </div>
        <div className="Picks-season-meta">{tp('season_meta', { weeks: season.weeks.length, picks })}</div>
      </div>
    </div>
  );
}

/** Points, accuracy and rank on the right of a season header. */
export function seasonStats(stats: Pick<ScopeStats, 'total_points' | 'accuracy' | 'rank'>, isCurrent: boolean): Mithril.Children[] {
  return [
    <div className="Picks-season-stat">
      <div className="Picks-season-statVal">{tp('points_value', { count: stats.total_points })}</div>
      <div className="Picks-season-statLbl">{tp('points')}</div>
    </div>,
    <div className="Picks-season-stat">
      <div className="Picks-season-statVal">{stats.accuracy.toFixed(0)}%</div>
      <div className="Picks-season-statLbl">{tp('accuracy')}</div>
    </div>,
    <div className="Picks-season-stat">
      <div className="Picks-season-statVal">{stats.rank != null ? `#${stats.rank}` : '—'}</div>
      <div className="Picks-season-statLbl">{tp(isCurrent ? 'rank' : 'final_rank')}</div>
    </div>,
  ];
}

/** The season summary footer under the week table. */
export function seasonFooter(stats: Pick<ScopeStats, 'total_picks' | 'correct_picks' | 'total_points'>): Mithril.Children {
  return (
    <div className="Picks-season-footer">
      <span>{tp('footer_picks', { count: stats.total_picks })}</span>
      <span>{tp('footer_record', { correct: stats.correct_picks, wrong: stats.total_picks - stats.correct_picks })}</span>
      <span>{tp('footer_points', { count: stats.total_points })}</span>
    </div>
  );
}
