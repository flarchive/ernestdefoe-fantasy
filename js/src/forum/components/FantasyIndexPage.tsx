import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import extractText from 'flarum/common/utils/extractText';
import CreateLeagueModal from './CreateLeagueModal';
import type League from '../models/League';

/** Every fantasy league on the forum. */
export default class FantasyIndexPage extends Page {
  loading = true;
  leagues: League[] = [];
  more = false;
  loadingMore = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    app.history.push('fantasy', extractText(app.translator.trans('ernestdefoe-fantasy.forum.title')));
    this.load();
  }

  /** A page of leagues, newest first; later pages are added below the first. */
  load(offset = 0) {
    return app.store
      .find<League[]>('fantasy-leagues', { page: { offset } })
      .then((leagues) => {
        this.leagues = [...(offset ? this.leagues : []), ...leagues];
        this.more = !!leagues.payload?.links?.next;
      })
      .catch(() => {
        // app.request has already shown the error.
      })
      .finally(() => {
        this.loading = false;
        this.loadingMore = false;
        m.redraw();
      });
  }

  loadMore() {
    this.loadingMore = true;
    this.load(this.leagues.length);
  }

  view() {
    return (
      <div className="FantasyPage">
        <div className="container">
          <h1>{app.translator.trans('ernestdefoe-fantasy.forum.title')}</h1>
          <p className="FantasyPage-intro">{app.translator.trans('ernestdefoe-fantasy.forum.intro')}</p>

          {/*
           * 🚨 Drawn only when the server says the permission is granted.
           * Guessing from `app.session.user` would show the button to every
           * member and then refuse half of them at the API, and a control that
           * appears and then says no is worse than one that was never there.
           */}
          {app.forum.attribute('canCreateFantasyLeague') ? (
            <Button
              className="Button Button--primary FantasyPage-start"
              icon="fas fa-plus"
              onclick={() =>
                app.modal.show(CreateLeagueModal, {
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

          {this.list()}
        </div>
      </div>
    );
  }

  /** The leagues as cards, or why there are none. */
  list() {
    return this.loading ? (
      <LoadingIndicator />
    ) : this.leagues.length === 0 ? (
      <p className="FantasyPage-empty">{app.translator.trans('ernestdefoe-fantasy.forum.empty')}</p>
    ) : (
      [
        <div className="FantasyGrid">
          {this.leagues.map((l) => (
            <Link className="FantasyCard" href={app.route('fantasy.league', { slug: l.slug() })}>
              <span className="FantasyCard-name">{l.name()}</span>
              <span className={'FantasyCard-status FantasyCard-status--' + l.status()}>
                {app.translator.trans('ernestdefoe-fantasy.forum.status.' + l.status())}
              </span>
              {l.description() ? <span className="FantasyCard-desc">{l.description()}</span> : null}
              <span className="FantasyCard-meta">
                {/*
                🚨 `trans` with a count, and the plural inside the STRING
                as ICU MessageFormat. Flarum 2 has no `transChoice` —
                calling it throws inside `view()`, and the failure is
                invisible: the redraw never lands and the page sits on a
                spinner.
              */}
                {app.translator.trans('ernestdefoe-fantasy.forum.franchise_count', { count: l.franchiseCount() })}
              </span>
            </Link>
          ))}
        </div>,
        this.more ? (
          <div className="FantasyPage-more">
            <Button className="Button" loading={this.loadingMore} onclick={() => this.loadMore()}>
              {app.translator.trans('ernestdefoe-fantasy.forum.load_more')}
            </Button>
          </div>
        ) : null,
      ]
    );
  }
}
