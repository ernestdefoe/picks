import Extend from 'flarum/common/extenders';
import Week from '../common/models/Week';
import Season from '../common/models/Season';

export default [
  new Extend.Store().add('picks-weeks', Week).add('picks-seasons', Season),

  new Extend.Routes()
    // Code-split: the pick'em pages load when someone opens them, not with
    // every page of the forum.
    .add('picks', '/picks', () => import('./components/PicksPage'))
    .add('picks.week', '/picks/week/:weekId', () => import('./components/PicksPage'))
    .add('user.picks-history', '/u/:username/picks-history', () => import('./components/UserPicksPage')),
];
