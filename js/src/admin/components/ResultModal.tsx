import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import extractText from 'flarum/common/utils/extractText';

const t = (key: string, params?: Record<string, unknown>) => app.translator.trans('ernestdefoe-picks.admin.games.' + key, params as any);

interface GameTeam {
  id: number;
  name: string;
}

interface Game {
  id: number;
  home_team: GameTeam | null;
  away_team: GameTeam | null;
  home_score: number | null;
  away_score: number | null;
}

interface ResultModalAttrs extends IInternalModalAttrs {
  game: Game;
  onsave: () => void;
}

export default class ResultModal extends Modal<ResultModalAttrs> {
  private game!: Game;
  private homeScore: string = '';
  private awayScore: string = '';
  loading: boolean = false;

  oninit(vnode: Mithril.Vnode<ResultModalAttrs, this>) {
    super.oninit(vnode);
    this.game = this.attrs.game;
    this.homeScore = this.game.home_score !== null ? String(this.game.home_score) : '';
    this.awayScore = this.game.away_score !== null ? String(this.game.away_score) : '';
  }

  className() {
    return 'ResultModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-picks.admin.games.enter_result');
  }

  /** Who the entered score says won, or null until both scores are in. */
  private resultPreview(): Mithril.Children {
    if (this.homeScore === '' || this.awayScore === '') return null;

    const home = Number(this.homeScore);
    const away = Number(this.awayScore);
    const homeName = this.game.home_team?.name || extractText(t('home_team'));
    const awayName = this.game.away_team?.name || extractText(t('away_team'));
    if (home > away) return t('wins', { team: homeName });
    if (away > home) return t('wins', { team: awayName });
    return t('tied');
  }

  content() {
    const resultPreview = this.resultPreview();

    return (
      <div className="Modal-body">
        <div className="Form">
          {this.renderHomeField()}

          {this.renderAwayField()}

          {resultPreview && (
            <div className="Form-group">
              <p className="PicksResultPreview">
                <strong>{app.translator.trans('ernestdefoe-picks.admin.games.result_preview')}:</strong> {resultPreview}
              </p>
            </div>
          )}

          <div className="Form-group">
            <Button className="Button Button--primary" loading={this.loading} onclick={() => this.save()}>
              {app.translator.trans('ernestdefoe-picks.admin.common.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  async save() {
    if (this.homeScore === '' || this.awayScore === '') return;

    this.loading = true;
    m.redraw();

    try {
      await app.request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/picks/events/${this.game.id}/result`,
        body: {
          homeScore: parseInt(this.homeScore),
          awayScore: parseInt(this.awayScore),
        },
      });

      this.attrs.onsave();
      this.hide();
    } catch (error: any) {
      this.loading = false;
      this.alertAttrs = error.alert;
      m.redraw();
    }
  }

  private renderHomeField() {
    return (
      <div className="Form-group">
        <label>{t('home_label', { team: this.game.home_team?.name ?? extractText(t('home_team')) })}</label>
        <input
          className="FormControl"
          type="number"
          min="0"
          placeholder="0"
          value={this.homeScore}
          oninput={(e: InputEvent) => {
            this.homeScore = (e.target as HTMLInputElement).value;
          }}
        />
      </div>
    );
  }

  private renderAwayField() {
    return (
      <div className="Form-group">
        <label>{t('away_label', { team: this.game.away_team?.name ?? extractText(t('away_team')) })}</label>
        <input
          className="FormControl"
          type="number"
          min="0"
          placeholder="0"
          value={this.awayScore}
          oninput={(e: InputEvent) => {
            this.awayScore = (e.target as HTMLInputElement).value;
          }}
        />
      </div>
    );
  }
}
