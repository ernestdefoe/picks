import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';

import PicksState from './picks/PicksState';
import MatchesTab from './picks/MatchesTab';
import MyPicksTab from './picks/MyPicksTab';
import LeaderboardTab from './picks/LeaderboardTab';
import HistoryTab from './picks/HistoryTab';

/**
 * Thin orchestrator for the /picks page. All state + data loading lives in
 * PicksState; each tab is its own presentational component reading from that
 * shared state. This component only owns the tab bar and active-tab dispatch.
 */
export default class PicksPage extends Page {
  private picksState = new PicksState();

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    app.setTitle(
      String(app.forum.attribute('picksNavLabel') || app.translator.trans('ernestdefoe-picks.lib.nav.picks')),
    );

    this.picksState.init(parseInt(String(m.route.param('weekId') ?? ''), 10));
  }

  view() {
    const state = this.picksState;
    const canView = app.forum.attribute('picksCanView') || app.session.user?.isAdmin();

    const tabs = [
      {
        key: 'matches',
        label: app.translator.trans('ernestdefoe-picks.lib.nav.matches'),
        icon: 'fas fa-football',
      },
      {
        key: 'mypicks',
        label: app.translator.trans('ernestdefoe-picks.lib.nav.my_picks'),
        icon: 'fas fa-check-circle',
      },
      {
        key: 'leaderboard',
        label: app.translator.trans('ernestdefoe-picks.lib.nav.leaderboard'),
        icon: 'fas fa-trophy',
      },
      {
        key: 'history',
        label: app.translator.trans('ernestdefoe-picks.lib.nav.history'),
        icon: 'fas fa-history',
      },
    ];

    return (
      <PageStructure className="PicksPage" sidebar={() => <IndexSidebar />}>
        <div className="PicksPage-inner">
          <div className="PicksPage-tabs">
            {tabs.map((tab) => (
              <button
                key={tab.key}
                className={`PicksPage-tab ${state.activeTab === tab.key ? 'PicksPage-tab--active' : ''}`}
                onclick={() => {
                  state.activeTab = tab.key;
                  if (tab.key === 'leaderboard' && state.leaderboard.length === 0) {
                    state.loadLeaderboard();
                  }
                  if (tab.key === 'history') {
                    state.loadLeaderboardHistory();
                  }
                  m.redraw();
                }}
              >
                <i className={tab.icon} /> {tab.label}
              </button>
            ))}
          </div>

          {!canView ? (
            <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.login_required')}</div>
          ) : !state.weeksLoaded ? (
            <LoadingIndicator />
          ) : state.weeks.length === 0 && state.activeTab !== 'history' ? (
            <div className="PicksEmpty PicksEmpty--noSchedule">
              <i className="fas fa-football" />
              <p>No schedule has been imported yet.</p>
              <p>Check back soon!</p>
            </div>
          ) : (
            <>
              {state.activeTab === 'matches' && MatchesTab.component({ state })}
              {state.activeTab === 'mypicks' && MyPicksTab.component({ state })}
              {state.activeTab === 'leaderboard' && LeaderboardTab.component({ state })}
              {state.activeTab === 'history' && HistoryTab.component({ state })}
            </>
          )}
        </div>
      </PageStructure>
    );
  }
}
