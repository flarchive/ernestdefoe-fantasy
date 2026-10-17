import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import crestUrl from '../crest';
import JoinLeagueModal from './JoinLeagueModal';
import extractText from 'flarum/common/utils/extractText';

declare const m: any;

/** One league: the table, everybody's roster, and the rules. */
export default class FantasyLeaguePage extends Page {
  loading = true;
  league: any = null;
  rules: any = {};
  table: any[] = [];
  viewer: any = {};
  franchises = 0;
  maxFranchises = 0;
  starting = false;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    app
      .request({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/fantasy/league`,
        params: { slug: m.route.param('slug') },
      })
      .then((data: any) => {
        this.league = data.league;
        this.rules = data.rules || {};
        this.table = data.table || [];
        this.viewer = data.viewer || {};
        this.franchises = data.franchises || 0;
        this.maxFranchises = data.maxFranchises || 0;
        this.loading = false;
        app.history.push('fantasy', this.league?.name || '');
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) return <LoadingIndicator />;
    if (!this.league)
      return (
        <div className="container">
          <p>{app.translator.trans('ernestdefoe-fantasy.forum.no_league')}</p>
        </div>
      );

    return (
      <div className="FantasyPage FantasyPage--league">
        <div className="container">
          <Link className="FantasyLeague-back" href={app.route('fantasy.index')}>
            {app.translator.trans('ernestdefoe-fantasy.forum.title')}
          </Link>

          <h1>{this.league.name}</h1>
          {this.league.description ? <p className="FantasyPage-intro">{this.league.description}</p> : null}

          {this.actions()}

          <section className="FantasySection">
            <h2>{app.translator.trans('ernestdefoe-fantasy.forum.standings')}</h2>
            <div className="FantasyTable-scroll">
              <table className="FantasyTable">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>{app.translator.trans('ernestdefoe-fantasy.forum.franchise')}</th>
                    <th>{app.translator.trans('ernestdefoe-fantasy.forum.member')}</th>
                    <th className="FantasyTable-num">{app.translator.trans('ernestdefoe-fantasy.forum.wins')}</th>
                    <th className="FantasyTable-num">{app.translator.trans('ernestdefoe-fantasy.forum.points')}</th>
                    <th>{app.translator.trans('ernestdefoe-fantasy.forum.roster')}</th>
                  </tr>
                </thead>
                <tbody>
                  {this.table.map((row: any, i: number) => (
                    <tr>
                      <td className="FantasyTable-num">{i + 1}</td>
                      <td className="FantasyTable-name">{row.name}</td>
                      <td>{row.member}</td>
                      <td className="FantasyTable-num">{row.wins}</td>
                      <td className="FantasyTable-num">{row.points.toFixed(2)}</td>
                      <td>
                        <span className="FantasyRoster">
                          {row.roster.map((t: any) =>
                            t.logo ? (
                              <img
                                className="FantasyRoster-crest"
                                src={crestUrl(t.logo, 22)}
                                title={t.name}
                                alt={t.name}
                                loading="lazy"
                                decoding="async"
                              />
                            ) : (
                              <span className="FantasyRoster-name">{t.name}</span>
                            )
                          )}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section className="FantasySection">
            <h2>{app.translator.trans('ernestdefoe-fantasy.forum.scoring')}</h2>
            <table className="FantasyTable FantasyTable--rules">
              <tbody>
                {this.rule('points_per_point')}
                {this.rule('points_per_point_allowed')}
                {this.rule('win_bonus')}
                {/*
                  🚨 Omitted entirely where a shutout cannot happen. A rule
                  listed at 0.00 that can never pay out reads as a broken rule
                  rather than as an impossible event, which is why the server
                  sends null for it rather than a zero.
                */}
                {this.rules.shutout_bonus !== null ? this.rule('shutout_bonus') : null}
                {this.rule('points_per_margin')}
              </tbody>
            </table>
          </section>
        </div>
      </div>
    );
  }

  /**
   * The one thing this visitor can do next, with where the league stands:
   * join while it takes members, start or enter the draft, set a lineup.
   */
  actions() {
    const l = this.league;
    const v = this.viewer;
    const slug = l.slug;
    const items: any[] = [];

    if (v.canJoin) {
      items.push(
        <Button
          className="Button Button--primary"
          icon="fas fa-user-plus"
          onclick={() => app.modal.show(JoinLeagueModal, { slug, league: l.name, onjoin: () => this.load() })}
        >
          {app.translator.trans('ernestdefoe-fantasy.forum.join')}
        </Button>
      );
    } else if (l.status === 'setup' && !app.session.user && this.franchises < this.maxFranchises) {
      items.push(
        <Button className="Button" icon="fas fa-sign-in-alt" onclick={() => app.modal.show(() => import('flarum/forum/components/LogInModal'))}>
          {app.translator.trans('ernestdefoe-fantasy.forum.log_in_to_join')}
        </Button>
      );
    }

    if (l.status === 'setup' && v.manager) {
      items.push(
        <Button
          className="Button"
          icon="fas fa-flag-checkered"
          loading={this.starting}
          disabled={this.franchises < 2}
          title={this.franchises < 2 ? extractText(app.translator.trans('ernestdefoe-fantasy.forum.start_draft_needs_two')) : undefined}
          onclick={() => this.startDraft()}
        >
          {app.translator.trans('ernestdefoe-fantasy.forum.start_draft')}
        </Button>
      );
    }

    if (l.status === 'drafting') {
      items.push(
        <LinkButton className="Button Button--primary" icon="fas fa-list-ol" href={app.route('fantasy.draft', { slug })}>
          {app.translator.trans('ernestdefoe-fantasy.forum.draft_room')}
        </LinkButton>
      );
    }

    if (l.status === 'active' && v.franchise) {
      items.push(
        <LinkButton className="Button Button--primary" icon="fas fa-clipboard-list" href={app.route('fantasy.lineup', { slug })}>
          {app.translator.trans('ernestdefoe-fantasy.forum.set_lineup')}
        </LinkButton>
      );
    }

    return (
      <div className="FantasyActions">
        <span className={'FantasyStatus FantasyStatus--' + l.status}>{app.translator.trans('ernestdefoe-fantasy.forum.status.' + l.status)}</span>
        {l.status === 'setup' ? (
          <span className="FantasyActions-note">
            {app.translator.trans('ernestdefoe-fantasy.forum.places', { taken: this.franchises, max: this.maxFranchises })}
          </span>
        ) : null}
        {items}
      </div>
    );
  }

  startDraft() {
    if (!confirm(extractText(app.translator.trans('ernestdefoe-fantasy.forum.start_draft_confirm')))) return;
    this.starting = true;
    app
      .request({ method: 'POST', url: `${app.forum.attribute('apiUrl')}/fantasy/leagues/${encodeURIComponent(this.league.slug)}/draft/start` })
      .then(() => m.route.set(app.route('fantasy.draft', { slug: this.league.slug })))
      .catch((err) => {
        this.starting = false;
        m.redraw();
        throw err;
      });
  }

  rule(key: string) {
    return (
      <tr>
        <td>{app.translator.trans('ernestdefoe-fantasy.forum.rule.' + key)}</td>
        <td className="FantasyTable-num">{Number(this.rules[key] ?? 0).toFixed(2)}</td>
      </tr>
    );
  }
}
