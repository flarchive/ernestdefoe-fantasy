import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';

declare const m: any;

/** One league: the table, everybody's roster, and the rules. */
export default class FantasyLeaguePage extends Page {
  loading = true;
  league: any = null;
  rules: any = {};
  table: any[] = [];

  oninit(vnode: any) {
    super.oninit(vnode);

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
    if (!this.league) return <div className="container"><p>{app.translator.trans('ernestdefoe-fantasy.forum.no_league')}</p></div>;

    return (
      <div className="FantasyPage FantasyPage--league">
        <div className="container">
          <Link className="FantasyLeague-back" href={app.route('fantasy.index')}>
            {app.translator.trans('ernestdefoe-fantasy.forum.title')}
          </Link>

          <h1>{this.league.name}</h1>
          {this.league.description ? <p className="FantasyPage-intro">{this.league.description}</p> : null}

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
                              <img className="FantasyRoster-crest" src={t.logo} title={t.name} alt={t.name} loading="lazy" />
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

  rule(key: string) {
    return (
      <tr>
        <td>{app.translator.trans('ernestdefoe-fantasy.forum.rule.' + key)}</td>
        <td className="FantasyTable-num">{Number(this.rules[key] ?? 0).toFixed(2)}</td>
      </tr>
    );
  }
}
