import Extend from 'flarum/common/extenders';
import app from 'flarum/admin/app';
import Team from '../common/models/Team';
import Season from '../common/models/Season';
import Week from '../common/models/Week';
import PickEvent from '../common/models/PickEvent';
import PicksPage from './components/PicksPage';

export default [
  new Extend.Store().add('picks-teams', Team),
  new Extend.Store().add('picks-seasons', Season),
  new Extend.Store().add('picks-weeks', Week),
  new Extend.Store().add('picks-events', PickEvent),

  new Extend.Admin()
    .page(PicksPage)
    .permission(
      () => ({
        icon: 'fas fa-football',
        label: app.translator.trans('ernestdefoe-picks.admin.permissions.manage'),
        permission: 'picks.manage',
      }),
      'moderate'
    )
    .permission(
      () => ({
        icon: 'fas fa-check-circle',
        label: app.translator.trans('ernestdefoe-picks.admin.permissions.make_picks'),
        permission: 'picks.makePicks',
      }),
      'start'
    )
    .permission(
      () => ({
        icon: 'fas fa-eye',
        label: app.translator.trans('ernestdefoe-picks.admin.permissions.view'),
        permission: 'picks.view',
        allowGuest: true,
      }),
      'view'
    )
    // ── New: controls visibility of member pick history profiles ──────────────
    .permission(
      () => ({
        icon: 'fas fa-history',
        label: app.translator.trans('ernestdefoe-picks.admin.permissions.view_history'),
        permission: 'picks.viewHistory',
      }),
      'view'
    ),
];
