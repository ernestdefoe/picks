import app from 'flarum/forum/app';

declare const m: any;

/**
 * The pick'em standings, offered to Page Builder.
 *
 * 🚨 Page Builder is not imported and not required. Picks works on a board that
 * has never heard of it, and a hard import would make this bundle fail to
 * evaluate where it is absent — taking the pick'em down with it.
 *
 * 🚨 Registered through the queue rather than `app.pageBuilder.registerBlock`.
 * `app.pageBuilder` does not exist until Page Builder's own initializer has run,
 * and which of two extensions initialises first is not something either decides.
 */
export default function registerBlocks(): void {
  const t = (k: string) => app.translator.trans(`ernestdefoe-picks.forum.leaderboard_block.${k}`);

  const component = {
    view(vnode: any) {
      const settings = vnode.attrs.settings || {};
      const rows: any[] = (vnode.attrs.data || {}).rows || [];

      if (rows.length === 0) {
        // 🚨 Nothing at all by default. Before the first week of a season there
        // is nobody to rank, and an empty table on a front page reads as broken.
        if (settings.hideWhenEmpty !== false) return null;

        return m('.PicksBoardBlock', [
          settings.title ? m('h3.PicksBoardBlock-title', settings.title) : null,
          m('p.PicksBoardBlock-empty', t('empty')),
        ]);
      }

      return m('.PicksBoardBlock', [
        settings.title ? m('h3.PicksBoardBlock-title', settings.title) : null,

        m('ol.PicksBoardBlock-list', rows.map((row: any) =>
          m('li.PicksBoardBlock-row', { key: row.id, className: row.rank <= 3 ? 'PicksBoardBlock-row--podium' : '' }, [
            // The medal is decoration; the number is the fact, and it is always
            // there for anyone the emoji does not reach.
            m('span.PicksBoardBlock-rank', row.rank),

            m('a.PicksBoardBlock-who', { href: app.route('user', { username: row.username }), oncreate: m.route.Link }, [
              row.avatarUrl
                ? m('img.PicksBoardBlock-avatar', { src: row.avatarUrl, alt: '', loading: 'lazy' })
                : m('span.PicksBoardBlock-avatar.PicksBoardBlock-avatar--initial',
                    String(row.displayName || row.username || '?').charAt(0).toUpperCase()),
              m('span.PicksBoardBlock-name', row.displayName),
            ]),

            m('span.PicksBoardBlock-record', row.correct + '–' + Math.max(0, row.total - row.correct)),
            m('span.PicksBoardBlock-acc', Math.round(row.accuracy) + '%'),
            m('span.PicksBoardBlock-pts', row.points),
          ])
        )),
      ]);
    },
  };

  const registry = (app as any).pageBuilder;

  if (registry && typeof registry.registerBlock === 'function') {
    registry.registerBlock('picks-leaderboard', component);
    return;
  }

  const queue = ((window as any).PageBuilderBlockQueue = (window as any).PageBuilderBlockQueue || []);
  queue.push({ type: 'picks-leaderboard', component });
}
