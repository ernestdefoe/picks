import app from 'flarum/common/app';

export type Side = 'home' | 'away';

/**
 * How a matchup is written: the forum's choice between "Home vs Away" (the
 * default, unchanged since the fork) and the American "Away @ Home".
 *
 * 🚨 The admin reads its own settings rather than the forum attribute, so the
 * admin's game lists follow a change the moment it is saved, not on the next
 * reload. The forum reads the one serialized attribute; no request of its own.
 */
export function awayFirst(): boolean {
  const settings = (app.data as any)?.settings;
  const value = settings ? settings['ernestdefoe-picks.matchup_format'] : app.forum?.attribute('picksMatchupFormat');

  return value === 'away_at_home';
}

/** The two sides in the order they are shown. */
export function sides(): [Side, Side] {
  return awayFirst() ? ['away', 'home'] : ['home', 'away'];
}

/**
 * The word between the teams. "@" says where the game is played, so a
 * neutral-site game keeps "vs" even when the away side is listed first.
 */
export function separator(neutral?: boolean | null): string {
  const key = awayFirst() && !neutral ? 'at' : 'vs';

  return app.translator.trans('ernestdefoe-picks.lib.common.' + key, {}, true) as string;
}

/** A score in the same order as the teams beside it. */
export function score(home: number | null, away: number | null, dash = '–'): string {
  return awayFirst() ? `${away}${dash}${home}` : `${home}${dash}${away}`;
}

/** "Home vs Away" or "Away @ Home" as plain text, for labels and titles. */
export function matchupText(home: string, away: string, neutral?: boolean | null): string {
  const [first, second] = awayFirst() ? [away, home] : [home, away];

  return `${first} ${separator(neutral)} ${second}`;
}
