import Component from 'flarum/common/Component';

declare const m: any;

/**
 * Loading skeletons for the picks pages.
 *
 * 🚨 Each surface reserves the height it rendered LAST time, not a modelled row
 * count. A week has a different number of games than the one before it, a
 * leaderboard grows through the season, and a member's own picks depend on how
 * many they made — none of it knowable before the response arrives. A
 * remembered height cannot drift from what the page actually draws.
 *
 * The fallbacks below are the only guessed numbers here, and they matter for
 * exactly one page view: a member's first. They come from the stylesheet rather
 * than from thin air — a leaderboard row measures 47px (12px padding either
 * side, a 26px avatar, 1px rule) with a 35px head, a game card is 1rem of
 * padding plus its rows, and a pick row is 12/12 around a 26px avatar with an
 * 8px gap under it.
 *
 * Storage access is wrapped: a browser in private mode, or one told to block
 * site data, throws on read rather than returning null.
 */
const KEY = 'ernestdefoe-picks.h.';

export function remember(surface: string, px: number): void {
  // A collapsed render is not worth learning from — it would train the
  // skeleton to reserve nothing.
  if (px < 20) return;

  try {
    localStorage.setItem(KEY + surface, String(Math.round(px)));
  } catch {
    // Storage unavailable; the fallback is used instead.
  }
}

function recalled(surface: string, fallback: number): number {
  try {
    const px = Number(localStorage.getItem(KEY + surface));

    // Capped: a stale or hand-edited value would reserve screens of empty page,
    // which is worse than the fallback.
    return Number.isFinite(px) && px >= 20 && px <= 8000 ? px : fallback;
  } catch {
    return fallback;
  }
}

/**
 * Hook a rendered element up to the memory, on its own lifecycle.
 *
 * 🚨 oncreate/onupdate rather than a requestAnimationFrame after the fetch —
 * rAF races Mithril's redraw and can run while the element does not yet exist,
 * storing nothing and leaving the memory doing no work at all.
 */
export function measure(surface: string) {
  const record = (v: any) => remember(surface, v.dom.getBoundingClientRect().height);

  return { oncreate: record, onupdate: record };
}

export default class PicksSkeleton extends Component {
  view(vnode: any) {
    const { surface, fallback, rows = 4, variant = '' } = vnode.attrs as {
      surface: string;
      fallback: number;
      rows?: number;
      variant?: string;
    };

    return (
      <div
        className={'PicksSkeleton' + (variant ? ' PicksSkeleton--' + variant : '')}
        style={{ height: recalled(surface, fallback) + 'px' }}
        aria-hidden="true"
      >
        {Array.from({ length: rows }, (_, i) => i).map((i) => (
          <span className="PicksSkeleton-bar" key={i} />
        ))}
      </div>
    );
  }
}
