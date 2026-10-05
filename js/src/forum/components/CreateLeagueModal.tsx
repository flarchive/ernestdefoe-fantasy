import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import withAttr from 'flarum/common/utils/withAttr';

/**
 * Start a league.
 *
 * 🚨 Three things this form deliberately does NOT ask for: how many
 * franchises, how big a roster, how many start each week. They are the forum's
 * settings, and asking here would mean every league carried this form's own
 * defaults while the admin screen governed nothing. The line under the fields
 * says what the league will be given, so the numbers are visible without being
 * editable in the one place that would make them meaningless.
 */
export default class CreateLeagueModal extends Modal {
  name = Stream('');
  description = Stream('');
  seasonId = Stream('');
  saving = false;

  oninit(vnode: any) {
    super.oninit(vnode);

    const seasons = this.attrs.seasons || [];
    if (seasons.length) this.seasonId(String(seasons[0].id));
  }

  className() {
    return 'FantasyCreateModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-fantasy.forum.start_league_title');
  }

  content() {
    const seasons = this.attrs.seasons || [];
    const d = this.attrs.defaults || {};

    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label>{app.translator.trans('ernestdefoe-fantasy.forum.league_name')}</label>
          <input
            className="FormControl"
            bidi={this.name}
            maxlength={189}
            placeholder={app.translator.trans('ernestdefoe-fantasy.forum.league_name_placeholder')}
          />
        </div>

        <div className="Form-group">
          <label>{app.translator.trans('ernestdefoe-fantasy.forum.league_description')}</label>
          <textarea className="FormControl" bidi={this.description} rows={3} maxlength={500} />
        </div>

        {seasons.length ? (
          <div className="Form-group">
            <label>{app.translator.trans('ernestdefoe-fantasy.forum.league_season')}</label>
            <select className="FormControl" value={this.seasonId()} onchange={withAttr('value', this.seasonId)}>
              {seasons.map((s: any) => (
                <option value={String(s.id)}>{s.name}</option>
              ))}
            </select>
          </div>
        ) : (
          <p className="helpText">{app.translator.trans('ernestdefoe-fantasy.forum.league_season_none')}</p>
        )}

        <p className="helpText">
          {app.translator.trans('ernestdefoe-fantasy.forum.league_defaults', {
            franchises: d.maxFranchises ?? 12,
            roster: d.rosterSize ?? 8,
            starters: d.starters ?? 4,
          })}
        </p>

        <div className="Form-group">
          <Button
            className="Button Button--primary Button--block"
            type="submit"
            loading={this.saving}
            disabled={this.saving || !this.name().trim()}
          >
            {app.translator.trans(
              this.saving ? 'ernestdefoe-fantasy.forum.creating' : 'ernestdefoe-fantasy.forum.create'
            )}
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
      .request<any>({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/fantasy/leagues`,
        body: { name: this.name().trim(), description: this.description().trim(), seasonId: Number(this.seasonId() || 0) },
      })
      .then((res) => {
        this.hide();
        /*
         * 🚨 Straight to the league, not back to a list the caller would have
         * to refetch. The commissioner already has a franchise in it — create()
         * joins them — so the next useful screen is the league itself.
         */
        m.route.set(app.route('fantasy.league', { slug: res.league.slug }));
      })
      .catch((err) => {
        this.saving = false;
        this.loading = false;
        m.redraw();
        throw err;
      });
  }
}
