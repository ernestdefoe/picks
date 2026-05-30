import app from 'flarum/admin/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import Team from '../../common/models/Team';

interface TeamEditModalAttrs extends IInternalModalAttrs {
  team: Team;
  onsave: () => void;
}

export default class TeamEditModal extends Modal<TeamEditModalAttrs> {
  private team!: Team;
  private name: string = '';
  private abbreviation: string = '';
  private conference: string = '';
  private logoPath: string = '';
  private logoDarkPath: string = '';
  private logoCustom: boolean = false;
  private loading: boolean = false;

  oninit(vnode: Mithril.Vnode<TeamEditModalAttrs, this>) {
    super.oninit(vnode);
    this.team = this.attrs.team;
    this.name = this.team.name() || '';
    this.abbreviation = this.team.abbreviation() || '';
    this.conference = this.team.conference() || '';
    this.logoPath = this.team.logoPath() || '';
    this.logoDarkPath = this.team.logoDarkPath() || '';
    this.logoCustom = this.team.logoCustom() || false;
  }

  className() {
    return 'TeamEditModal Modal--medium';
  }

  title() {
    return app.translator.trans('ernestdefoe-picks.admin.teams.edit_title');
  }

  content() {
    const logoUrl = this.team.logoUrl();
    const logoDarkUrl = this.team.logoDarkUrl();

    return (
      <div className="Modal-body">
        <div className="Form">

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.teams.fields.name')}</label>
            <input
              className="FormControl"
              type="text"
              value={this.name}
              oninput={(e: InputEvent) => { this.name = (e.target as HTMLInputElement).value; }}
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.teams.fields.abbreviation')}</label>
            <input
              className="FormControl"
              type="text"
              maxlength="10"
              value={this.abbreviation}
              oninput={(e: InputEvent) => { this.abbreviation = (e.target as HTMLInputElement).value; }}
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.teams.fields.conference')}</label>
            <input
              className="FormControl"
              type="text"
              value={this.conference}
              oninput={(e: InputEvent) => { this.conference = (e.target as HTMLInputElement).value; }}
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.teams.fields.logo_standard')}</label>
            {logoUrl && (
              <div className="TeamEditModal-logoPreview">
                <img src={logoUrl} alt={this.name} />
              </div>
            )}
            <input
              className="FormControl"
              type="text"
              placeholder="https://..."
              value={this.logoPath}
              oninput={(e: InputEvent) => { this.logoPath = (e.target as HTMLInputElement).value; }}
            />
          </div>

          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-picks.admin.teams.fields.logo_dark')}</label>
            {logoDarkUrl && (
              <div className="TeamEditModal-logoPreview TeamEditModal-logoPreview--dark">
                <img src={logoDarkUrl} alt={this.name + ' dark'} />
              </div>
            )}
            <input
              className="FormControl"
              type="text"
              placeholder="https://..."
              value={this.logoDarkPath}
              oninput={(e: InputEvent) => { this.logoDarkPath = (e.target as HTMLInputElement).value; }}
            />
          </div>

          <div className="Form-group">
            <label className="checkbox">
              <input
                type="checkbox"
                checked={this.logoCustom}
                onchange={(e: InputEvent) => { this.logoCustom = (e.target as HTMLInputElement).checked; }}
              />
              {app.translator.trans('ernestdefoe-picks.admin.teams.fields.logo_custom')}
            </label>
            <p className="helpText">
              {app.translator.trans('ernestdefoe-picks.admin.teams.fields.logo_custom_help')}
            </p>
          </div>

          <div className="Form-group">
            <Button
              className="Button Button--primary"
              type="submit"
              loading={this.loading}
            >
              {app.translator.trans('ernestdefoe-picks.admin.common.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  async onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    m.redraw();

    try {
      await this.team.save({
        name: this.name,
        abbreviation: this.abbreviation || null,
        conference: this.conference || null,
        logoPath: this.logoPath || null,
        logoDarkPath: this.logoDarkPath || null,
        logoCustom: this.logoCustom,
      });

      this.attrs.onsave();
      this.hide();
    } catch (error: any) {
      this.loading = false;
      this.alertAttrs = error.alert;
      m.redraw();
    }
  }
}
