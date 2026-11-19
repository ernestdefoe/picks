import app from 'flarum/forum/app';
import type Mithril from 'mithril';

/**
 * Breadcrumbs from ernestdefoe/waymark, when it is installed.
 *
 * 🚨 Drawn by the page, not by Waymark. Left to itself Waymark puts its trail
 * between PageStructure's hero and container — and /picks has no hero, so the
 * trail sat above the whole container, over the sidebar's "Start a Discussion"
 * rather than over the page it names. The page claims its routes (a resolver
 * that answers null, so Waymark's own trail stands down) and draws the trail
 * directly above its tab bar instead. Without Waymark every call is a no-op.
 */
type Crumb = { label: string; href?: string };

const ROUTES = ['picks', 'picks.week'];

let claimed = false;

/** Waymark's API, only while ernestdefoe/waymark is enabled. */
function api(): any {
  return 'ernestdefoe-waymark' in flarum.extensions ? (app as any).waymark : null;
}

/** Stop Waymark drawing its own trail on these routes. Safe to call every init. */
export function claimWaymarkRoutes(): void {
  const w = api();
  if (claimed || !w || typeof w.register !== 'function') return;

  ROUTES.forEach((name) => w.register(name, () => null));
  claimed = true;
}

export function waymark(crumbs: Crumb[]): Mithril.Children {
  const w = api();
  if (!w || typeof w.render !== 'function') return null;

  try {
    return w.render(crumbs);
  } catch {
    // A trail is never worth breaking the page it sits on.
    return null;
  }
}
