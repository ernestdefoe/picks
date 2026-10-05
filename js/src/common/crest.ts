/**
 * A team crest at the size it is drawn.
 *
 * 🚨 ESPN's crests are 500×500 PNGs of about 30 KB, and they are drawn at a
 * few dozen pixels — a page of them was megabytes of pixels thrown away. ESPN's
 * own resizer serves the same transparent PNG at any size (a 64px one is about
 * 2 KB), so the crest is asked for at twice its CSS size, which keeps it sharp
 * on a retina screen.
 *
 * Only an ESPN team logo is rewritten. An uploaded crest or another CDN's URL
 * passes through untouched, and so does one that is already resized — the
 * resizer's path is `/combiner/i`, which the pattern does not match.
 */
const ESPN_LOGO = /^(?:https?:)?\/\/a\.espncdn\.com(\/i\/teamlogos\/[^?#]+)$/i;

export default function crestUrl(url: string, cssPx: number): string {
  const match = url ? ESPN_LOGO.exec(url) : null;
  if (!match) return url;

  const px = Math.round(cssPx * 2);

  return `https://a.espncdn.com/combiner/i?img=${match[1]}&w=${px}&h=${px}`;
}
