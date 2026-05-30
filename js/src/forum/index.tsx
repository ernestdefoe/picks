import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import LinkButton from 'flarum/common/components/LinkButton';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import UserPage from 'flarum/forum/components/UserPage';

export { default as extend } from './extend.tsx';

app.initializers.add('ernestdefoe/picks', () => {
  // Forum sidebar nav item
  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (!app.forum.attribute('picksCanView') && !app.session.user?.isAdmin()) return;

    items.add(
      'picks',
      <LinkButton href={app.route('picks')} icon="fas fa-football">
        {app.forum.attribute('picksNavLabel') || app.translator.trans('ernestdefoe-picks.lib.nav.picks')}
      </LinkButton>,
      80
    );
  });

  // User profile sidebar nav item — Picks History
  extend(UserPage.prototype, 'navItems', function (items) {
    const profileUser = app.current.get('user');
    if (!profileUser) return;

    const isOwnProfile = app.session.user?.id?.() === profileUser.id?.();
    const isAdmin      = app.session.user?.isAdmin?.();
    const canView      = app.forum.attribute<boolean>('picksCanViewHistory');

    if (!isOwnProfile && !isAdmin && !canView) return;

    items.add(
      'picks-history',
      <LinkButton
        href={app.route('user.picks-history', { username: profileUser.username?.() })}
        icon="fas fa-football"
      >
        Picks History
      </LinkButton>,
      75
    );
  });
});
