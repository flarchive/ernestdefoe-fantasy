import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';

declare const m: any;

interface JoinLeagueModalAttrs extends IFormModalAttrs {
  slug: string;
  league: string;
  onjoin: () => void;
}

/** Take a franchise in a league that is still taking members: just a name for it. */
export default class JoinLeagueModal extends FormModal<JoinLeagueModalAttrs> {
  name = Stream('');
  saving = false;

  className() {
    return 'FantasyJoinModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-fantasy.forum.join_title', { league: this.attrs.league });
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label>{app.translator.trans('ernestdefoe-fantasy.forum.franchise_name')}</label>
          <input
            className="FormControl"
            bidi={this.name}
            maxlength={119}
            placeholder={app.translator.trans('ernestdefoe-fantasy.forum.franchise_name_placeholder')}
          />
        </div>
        <div className="Form-group">
          <Button className="Button Button--primary Button--block" type="submit" loading={this.saving} disabled={this.saving || !this.name().trim()}>
            {app.translator.trans(this.saving ? 'ernestdefoe-fantasy.forum.joining' : 'ernestdefoe-fantasy.forum.join')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: Event) {
    e.preventDefault();
    if (this.saving) return;
    this.saving = true;

    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/fantasy/leagues/${encodeURIComponent(this.attrs.slug)}/join`,
        body: { name: this.name().trim() },
      })
      .then(() => {
        this.hide();
        this.attrs.onjoin();
      })
      .catch((err) => {
        this.saving = false;
        this.loading = false;
        m.redraw();
        throw err;
      });
  }
}
