import app from 'flarum/forum/app';

// ── Helpers ───────────────────────────────────────────────────────────────────

export const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-picks.forum.profile_stats.${key}`, params);

/** The profile page's own words, beside the stat cards' above. */
export const tp = (key: string, params: Record<string, unknown> = {}) => app.translator.trans(`ernestdefoe-picks.forum.profile.${key}`, params);

export function fmt(n: number | null | undefined, suffix = ''): string {
  if (n == null) return '—';
  return `${n}${suffix}`;
}

export function accClass(accuracy: number): string {
  if (accuracy >= 75) return 'Picks-profile-accPill--high';
  if (accuracy >= 50) return 'Picks-profile-accPill--med';
  return 'Picks-profile-accPill--low';
}
