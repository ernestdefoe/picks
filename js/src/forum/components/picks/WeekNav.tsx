import app from 'flarum/forum/app';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type PicksState from './PicksState';

/** The week title, its dates, and the arrows — shared by the Games and Confidence tabs. */
export default function WeekNav(state: PicksState): Mithril.Children {
  const week = state.currentWeek();
  const idx = state.weeks.findIndex((w) => w.id === state.currentWeekId);

  return (
    <div className="PicksWeekNav">
      <div>
        <div className="PicksWeekNav-title">{week?.name || '—'}</div>
        {week?.start_date && (
          <div className="PicksWeekNav-dates">
            {week.start_date} – {week.end_date}
          </div>
        )}
      </div>
      <div className="PicksWeekNav-arrows">
        {state.thisWeekId && state.thisWeekId !== state.currentWeekId && (
          <Button className="Button PicksWeekNav-thisWeek" icon="fas fa-calendar-day" onclick={() => state.goToThisWeek()}>
            {app.translator.trans('ernestdefoe-picks.lib.nav.this_week')}
          </Button>
        )}
        <Button
          className="Button Button--icon"
          icon="fas fa-chevron-left"
          aria-label={app.translator.trans('ernestdefoe-picks.forum.week_nav.previous')}
          disabled={idx <= 0}
          onclick={() => state.prevWeek()}
        />
        <Button
          className="Button Button--icon"
          icon="fas fa-chevron-right"
          aria-label={app.translator.trans('ernestdefoe-picks.forum.week_nav.next')}
          disabled={idx >= state.weeks.length - 1}
          onclick={() => state.nextWeek()}
        />
      </div>
    </div>
  );
}
