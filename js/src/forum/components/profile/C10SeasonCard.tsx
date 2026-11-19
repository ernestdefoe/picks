import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import extractText from 'flarum/common/utils/extractText';
import { accClass, tp } from './helpers';
import { seasonFooter, seasonLeft, seasonStats } from './SeasonParts';
import type { C10Season, C10Week } from './types';

/** One season of the member's Confidence record, with the tiebreaker distance beside each week. */
export default function C10SeasonCard(season: C10Season, open: boolean, toggle: () => void): Mithril.Children {
  const stats = season.stats;

  return (
    <div className="Picks-season-card" key={'c10-' + season.season_id}>
      <button type="button" className="Picks-season-header" aria-expanded={open ? 'true' : 'false'} onclick={toggle}>
        {seasonLeft(season, stats.total_picks)}

        <div className="Picks-season-right">
          {seasonStats(stats, season.is_current)}
          <span className={`Picks-season-chevron ${open ? 'Picks-season-chevron--open' : ''}`} aria-hidden="true">
            &#8964;
          </span>
        </div>
      </button>

      {open && (
        <div className="Picks-season-body">
          {weekTable(season)}

          {seasonFooter(stats)}
        </div>
      )}
    </div>
  );
}

function weekTable(season: C10Season): Mithril.Children {
  return (
    <table className="Picks-week-table Picks-week-table--c10">
      <thead>
        <tr>
          <th>{tp('col_week')}</th>
          <th className="Picks-week-table-r Picks-week-col--wl">{tp('col_won')}</th>
          <th className="Picks-week-table-r Picks-week-col--wl">{tp('col_lost')}</th>
          <th className="Picks-week-table-r">{tp('col_accuracy')}</th>
          <th className="Picks-week-table-r">{tp('col_points')}</th>
          <th className="Picks-week-table-r" title={extractText(app.translator.trans('ernestdefoe-picks.forum.confidence.col_tiebreaker_title'))}>
            {tp('col_tiebreaker')}
          </th>
          <th className="Picks-week-table-r">{tp('col_rank')}</th>
        </tr>
      </thead>
      <tbody>{season.weeks.map((week) => weekRow(week))}</tbody>
    </table>
  );
}

function weekRow(week: C10Week): Mithril.Children {
  return (
    <tr key={String(week.week_id)}>
      <td>
        <span className="Picks-week-name">{week.week_name}</span>
        {week.is_current && <span className="Picks-season-openBadge">{tp('active')}</span>}
      </td>
      <td className="Picks-week-table-r Picks-week-col--wl">{week.correct_picks}</td>
      <td className="Picks-week-table-r Picks-week-col--wl">{week.total_picks - week.correct_picks}</td>
      <td className="Picks-week-table-r">
        <span className={`Picks-profile-accPill ${accClass(week.accuracy)}`}>{week.accuracy.toFixed(0)}%</span>
      </td>
      <td className="Picks-week-table-r">
        <strong>{week.total_points}</strong>
      </td>
      <td className="Picks-week-table-r Picks-week-rank">{week.tiebreak_diff != null ? `±${week.tiebreak_diff}` : '—'}</td>
      <td className="Picks-week-table-r Picks-week-rank">{week.rank != null ? `#${week.rank}` : '—'}</td>
    </tr>
  );
}
