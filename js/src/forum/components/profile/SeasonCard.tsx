import type Mithril from 'mithril';
import { accClass, tp } from './helpers';
import { seasonFooter, seasonLeft, seasonStats } from './SeasonParts';
import type { SeasonHistory, WeekHistory } from './types';

/** One season of the member's regular pick'em history, collapsed to its header until opened. */
export default function SeasonCard(season: SeasonHistory, isExpanded: boolean, toggle: () => void): Mithril.Children {
  const stats = season.stats;

  return (
    <div className="Picks-season-card" key={String(season.season_id)}>
      {/* Header row — always visible, click to expand/collapse */}
      <div className="Picks-season-header" onclick={toggle}>
        {seasonLeft(season, stats ? stats.total_picks : 0)}

        <div className="Picks-season-right">
          {stats && stats.total_picks > 0 ? (
            <>{seasonStats(stats, season.is_current)}</>
          ) : (
            <div className="Picks-season-stat">
              <div className="Picks-season-statLbl">{tp('no_picks')}</div>
            </div>
          )}
          <span className={`Picks-season-chevron ${isExpanded ? 'Picks-season-chevron--open' : ''}`}>&#8964;</span>
        </div>
      </div>

      {/* Week table — only visible when expanded */}
      {isExpanded && <div className="Picks-season-body">{seasonBody(season)}</div>}
    </div>
  );
}

function seasonBody(season: SeasonHistory): Mithril.Children {
  const stats = season.stats;

  if (season.weeks.length === 0) {
    return (
      <div className="Picks-profile-empty" style="padding: 1rem 1.1rem;">
        {tp('empty_season')}
      </div>
    );
  }

  return (
    <>
      <table className="Picks-week-table">
        <thead>
          <tr>
            <th>{tp('col_week')}</th>
            <th className="Picks-week-table-r">{tp('col_won')}</th>
            <th className="Picks-week-table-r">{tp('col_lost')}</th>
            <th className="Picks-week-table-r">{tp('col_accuracy')}</th>
            <th className="Picks-week-table-r">{tp('col_points')}</th>
            <th className="Picks-week-table-r">{tp('col_rank')}</th>
          </tr>
        </thead>
        <tbody>{season.weeks.map((week) => weekRow(week))}</tbody>
      </table>

      {/* Season summary footer */}
      {stats && stats.total_picks > 0 && seasonFooter(stats)}
    </>
  );
}

function weekRow(week: WeekHistory): Mithril.Children {
  return (
    <tr key={String(week.week_id)}>
      <td>
        <span className="Picks-week-name">{week.week_name}</span>
        {week.is_current && <span className="Picks-season-openBadge">{tp('active')}</span>}
      </td>
      <td className="Picks-week-table-r">{week.correct_picks}</td>
      <td className="Picks-week-table-r">{week.total_picks - week.correct_picks}</td>
      {week.accuracy != null ? (
        <td className="Picks-week-table-r">
          <span className={`Picks-profile-accPill ${accClass(week.accuracy)}`}>{week.accuracy.toFixed(0)}%</span>
        </td>
      ) : (
        <td className="Picks-week-table-r Picks-week-rank">—</td>
      )}
      <td className="Picks-week-table-r">
        <strong>{week.total_points}</strong>
      </td>
      <td className="Picks-week-table-r Picks-week-rank">{week.rank != null ? `#${week.rank}` : '—'}</td>
    </tr>
  );
}
