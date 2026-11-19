import app from 'flarum/forum/app';
import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Stream from 'flarum/common/utils/Stream';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import withAttr from 'flarum/common/utils/withAttr';
import type League from '../models/League';

interface CreateLeagueModalAttrs extends IFormModalAttrs {
  defaults?: { maxFranchises?: number; rosterSize?: number; starters?: number };
}

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
export default class CreateLeagueModal extends FormModal<CreateLeagueModalAttrs> {
  name = Stream('');
  description = Stream('');
  seasonId = Stream('');
  seasons: { id: number; name: string }[] = [];
  seasonsLoading = true;
  saving = false;

  oninit(vnode: any) {
    super.oninit(vnode);

    // Picks' seasons, newest first. Picks registers no forum model for them, so they are read as plain JSON:API.
    app
      .request<any>({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/picks-seasons`, params: { page: { limit: 50 } } })
      .then((res) => {
        this.seasons = (res.data || [])
          .map((s: any) => ({ id: Number(s.id), name: String(s.attributes.name || s.attributes.year || s.id) }))
          .sort((a: { id: number }, b: { id: number }) => b.id - a.id);
        if (this.seasons.length) this.seasonId(String(this.seasons[0].id));
      })
      .catch(() => {
        // app.request has already shown the error; the form says there is no season.
      })
      .finally(() => {
        this.seasonsLoading = false;
        m.redraw();
      });
  }

  className() {
    return 'FantasyCreateModal Modal--small';
  }

  title() {
    return app.translator.trans('ernestdefoe-fantasy.forum.start_league_title');
  }

  content() {
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
          <textarea className="FormControl" bidi={this.description} rows={3} maxlength={2000} />
        </div>

        {this.seasonField()}

        <p className="helpText">
          {app.translator.trans('ernestdefoe-fantasy.forum.league_defaults', {
            franchises: d.maxFranchises,
            roster: d.rosterSize,
            starters: d.starters,
          })}
        </p>

        <div className="Form-group">
          <Button
            className="Button Button--primary Button--block"
            type="submit"
            loading={this.saving}
            disabled={this.saving || !this.name().trim() || !this.seasonId()}
          >
            {app.translator.trans(this.saving ? 'ernestdefoe-fantasy.forum.creating' : 'ernestdefoe-fantasy.forum.create')}
          </Button>
        </div>
      </div>
    );
  }

  /** The Picks season the league plays: required, so the form can't be sent without one. */
  seasonField() {
    const seasons = this.seasons;

    return this.seasonsLoading ? (
      <LoadingIndicator size="small" display="block" />
    ) : seasons.length ? (
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
    );
  }

  onsubmit(e: Event) {
    e.preventDefault();
    if (this.saving) return;
    this.saving = true;

    app.store
      .createRecord<League>('fantasy-leagues')
      .save({ name: this.name().trim(), description: this.description().trim() || null, seasonId: Number(this.seasonId() || 0) })
      .then((league) => {
        this.hide();
        /*
         * 🚨 Straight to the league, not back to a list the caller would have
         * to refetch. The commissioner already has a franchise in it — create()
         * joins them — so the next useful screen is the league itself.
         */
        m.route.set(app.route('fantasy.league', { slug: league.slug() }));
      })
      .catch((err) => {
        this.saving = false;
        this.loading = false;
        m.redraw();
        throw err;
      });
  }
}
