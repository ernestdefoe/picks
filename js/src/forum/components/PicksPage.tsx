import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import type Mithril from 'mithril';

import PicksState from './picks/PicksState';
import MatchesTab from './picks/MatchesTab';
import MyPicksTab from './picks/MyPicksTab';
import LeaderboardTab from './picks/LeaderboardTab';
import HistoryTab from './picks/HistoryTab';
import ConfidenceTab from './picks/ConfidenceTab';
import PicksSkeleton from './PicksSkeleton';
import { claimWaymarkRoutes, waymark } from '../waymark';

/**
 * Thin orchestrator for the /picks page. All state + data loading lives in
 * PicksState; each tab is its own presentational component reading from that
 * shared state. This component only owns the tab bar and active-tab dispatch.
 */
export default class PicksPage extends Page {
  private picksState = new PicksState();

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    claimWaymarkRoutes();

    app.setTitle(this.title());

    this.picksState.init(parseInt(String(m.route.param('weekId') ?? ''), 10));
  }

  private title(): string {
    return String(app.forum.attribute('picksNavLabel') || app.translator.trans('ernestdefoe-picks.lib.nav.picks'));
  }

  view() {
    return (
      <PageStructure className="PicksPage" sidebar={() => <IndexSidebar />}>
        <div className="PicksPage-inner">
          {waymark([{ label: this.title() }])}

          <div className="PicksPage-tabs">{this.tabs().map((tab) => this.tabButton(tab))}</div>

          {this.content()}
        </div>
      </PageStructure>
    );
  }

  private tabs(): { key: string; label: Mithril.Children; icon: string }[] {
    return [
      {
        key: 'matches',
        label: app.translator.trans('ernestdefoe-picks.lib.nav.matches'),
        icon: 'fas fa-football',
      },
      // The Confidence contest, when the forum runs one. Read here, in view(),
      // because app.forum is not there yet when the page module loads.
      ...(app.forum.attribute('picksC10Enabled')
        ? [
            {
              key: 'confidence',
              label: app.translator.trans('ernestdefoe-picks.forum.confidence.tab', {
                count: app.forum.attribute('picksC10Games') || 10,
              }),
              icon: 'fas fa-sort-amount-down',
            },
          ]
        : []),
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
  }

  private tabButton(tab: { key: string; label: Mithril.Children; icon: string }): Mithril.Children {
    const state = this.picksState;

    return (
      <button
        key={tab.key}
        className={`PicksPage-tab ${state.activeTab === tab.key ? 'PicksPage-tab--active' : ''}`}
        onclick={() => this.selectTab(tab.key)}
      >
        <i className={tab.icon} /> {tab.label}
      </button>
    );
  }

  private selectTab(key: string): void {
    const state = this.picksState;

    state.activeTab = key;
    if (key === 'leaderboard' && state.leaderboard.length === 0) {
      state.loadLeaderboard();
    }
    if (key === 'history') {
      state.loadLeaderboardHistory();
    }
    if (key === 'confidence' && state.c10.weekId !== state.currentWeekId) {
      state.c10.load(state.currentWeekId);
    }
    m.redraw();
  }

  private content(): Mithril.Children {
    const state = this.picksState;
    const canView = app.forum.attribute('picksCanView') || app.session.user?.isAdmin();

    if (!canView) {
      return <div className="PicksEmpty">{app.translator.trans('ernestdefoe-picks.lib.messages.login_required')}</div>;
    }

    if (!state.weeksLoaded) {
      return <PicksSkeleton surface="page" fallback={430} rows={4} />;
    }

    if (state.weeks.length === 0 && state.activeTab !== 'history') {
      return (
        <div className="PicksEmpty PicksEmpty--noSchedule">
          <i className="fas fa-football" />
          <p>{app.translator.trans('ernestdefoe-picks.lib.messages.no_schedule')}</p>
          <p>{app.translator.trans('ernestdefoe-picks.lib.messages.check_back')}</p>
        </div>
      );
    }

    return (
      <>
        {state.activeTab === 'matches' && MatchesTab.component({ state })}
        {state.activeTab === 'confidence' && ConfidenceTab.component({ state })}
        {state.activeTab === 'mypicks' && MyPicksTab.component({ state })}
        {state.activeTab === 'leaderboard' && LeaderboardTab.component({ state })}
        {state.activeTab === 'history' && HistoryTab.component({ state })}
      </>
    );
  }
}
