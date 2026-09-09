import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import CreateLeagueModal from './CreateLeagueModal';

declare const m: any;

/** Every fantasy league on the forum. */
export default class FantasyIndexPage extends Page {
  loading = true;
  leagues: any[] = [];
  seasons: any[] = [];
  canCreate = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    app.history.push('fantasy', app.translator.trans('ernestdefoe-fantasy.forum.title'));

    app
      .request({ method: 'GET', url: `${app.forum.attribute('apiUrl')}/fantasy/leagues` })
      .then((data: any) => {
        this.leagues = data.leagues || [];
        this.seasons = data.seasons || [];
        this.canCreate = !!data.canCreate;
        this.loading = false;
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    return (
      <div className="FantasyPage">
        <div className="container">
          <h1>{app.translator.trans('ernestdefoe-fantasy.forum.title')}</h1>
          <p className="FantasyPage-intro">{app.translator.trans('ernestdefoe-fantasy.forum.intro')}</p>

          {/*
            * 🚨 Drawn only once the request has answered, and only if it said
            * yes. Guessing from `app.session.user` would show the button to
            * every member and then refuse half of them at the API — the server
            * is the only thing that knows whether the permission is granted,
            * and a control that appears and then says no is worse than one
            * that was never there.
            */}
          {!this.loading && this.canCreate ? (
            <Button
              className="Button Button--primary FantasyPage-start"
              icon="fas fa-plus"
              onclick={() =>
                app.modal.show(CreateLeagueModal, {
                  seasons: this.seasons,
                  defaults: {
                    maxFranchises: app.forum.attribute('fantasyMaxFranchises'),
                    rosterSize: app.forum.attribute('fantasyRosterSize'),
                    starters: app.forum.attribute('fantasyStarters'),
                  },
                })
              }
            >
              {app.translator.trans('ernestdefoe-fantasy.forum.start_league')}
            </Button>
          ) : null}

          {this.loading ? (
            <LoadingIndicator />
          ) : this.leagues.length === 0 ? (
            <p className="FantasyPage-empty">{app.translator.trans('ernestdefoe-fantasy.forum.empty')}</p>
          ) : (
            <div className="FantasyGrid">
              {this.leagues.map((l: any) => (
                <Link className="FantasyCard" href={app.route('fantasy.league', { slug: l.slug })}>
                  <span className="FantasyCard-name">{l.name}</span>
                  <span className={'FantasyCard-status FantasyCard-status--' + l.status}>
                    {app.translator.trans('ernestdefoe-fantasy.forum.status.' + l.status)}
                  </span>
                  {l.description ? <span className="FantasyCard-desc">{l.description}</span> : null}
                  <span className="FantasyCard-meta">
                    {/*
                      🚨 `trans` with a count, and the plural inside the STRING
                      as ICU MessageFormat. Flarum 2 has no `transChoice` —
                      calling it throws inside `view()`, and the failure is
                      invisible: the redraw never lands and the page sits on a
                      spinner.
                    */}
                    {app.translator.trans('ernestdefoe-fantasy.forum.franchise_count', { count: l.franchises })}
                  </span>
                </Link>
              ))}
            </div>
          )}
        </div>
      </div>
    );
  }
}
