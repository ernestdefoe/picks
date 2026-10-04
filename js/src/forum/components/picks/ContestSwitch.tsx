import app from 'flarum/forum/app';
import type Mithril from 'mithril';

/**
 * Full board | Confidence N — which contest a standings tab is showing. Only
 * drawn when the forum runs the Confidence contest at all.
 */
export default function ContestSwitch(current: 'main' | 'c10', onchange: (contest: 'main' | 'c10') => void): Mithril.Children {
  if (!app.forum.attribute('picksC10Enabled')) return null;

  const options: Array<['main' | 'c10', Mithril.Children]> = [
    ['main', app.translator.trans('ernestdefoe-picks.forum.confidence.full_board')],
    ['c10', app.translator.trans('ernestdefoe-picks.forum.confidence.tab', { count: app.forum.attribute('picksC10Games') || 10 })],
  ];

  return (
    <div className="PicksContestSwitch" role="tablist">
      {options.map(([key, label]) => (
        <button
          type="button"
          role="tab"
          aria-selected={current === key ? 'true' : 'false'}
          className={`PicksContestSwitch-option ${current === key ? 'PicksContestSwitch-option--active' : ''}`}
          onclick={() => current !== key && onchange(key)}
        >
          {label}
        </button>
      ))}
    </div>
  );
}
