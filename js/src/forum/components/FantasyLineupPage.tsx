import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import Page, { IPageAttrs } from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import crestUrl from '../crest';
import type { LineupData, LineupGame, LineupTeam } from '../types';

declare const m: any;

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans('ernestdefoe-fantasy.forum.lineup.' + key, params);

/**
 * Who starts this week. Every team on the roster with its game; switch a team
 * in or out until the game kicks off, then it stays where it is. A week left
 * alone plays the lineup from the week before.
 */
export default class FantasyLineupPage extends Page {
  loading = true;
  data: LineupData | null = null;
  chosen = new Set<number>();
  saved = new Set<number>();
  saving = false;

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  load(week?: number) {
    this.loading = true;
    return app
      .request<LineupData>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/fantasy/lineup`,
        params: { slug: m.route.param('slug'), week: week || undefined },
      })
      .then((data: LineupData) => {
        this.data = data;
        this.saved = new Set(data.teams.filter((x) => x.starting).map((x) => x.id));
        this.chosen = new Set(this.saved);
        this.loading = false;
        app.setTitle(extractText(t('title', { franchise: data.franchise.name })));
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading && !this.data) return <LoadingIndicator />;
    if (!this.data)
      return (
        <div className="container">
          <p>{app.translator.trans('ernestdefoe-fantasy.forum.no_league')}</p>
        </div>
      );

    const { league, franchise, weeks, week, teams } = this.data;
    const count = this.chosen.size;
    const changed = count !== this.saved.size || [...this.chosen].some((id) => !this.saved.has(id));

    return (
      <div className="FantasyPage FantasyPage--lineup">
        <div className="container">
          <Link className="FantasyLeague-back" href={app.route('fantasy.league', { slug: league.slug })}>
            {league.name}
          </Link>
          <h1>{t('title', { franchise: franchise.name })}</h1>

          {league.status !== 'active' ? (
            <p className="FantasyPage-intro">{t('not_yet')}</p>
          ) : (
            <form onsubmit={(e: Event) => this.save(e)}>
              <div className="FantasyLineup-head">
                <select
                  className="FormControl FantasyLineup-week"
                  value={String(week)}
                  aria-label={extractText(t('week'))}
                  onchange={(e: Event) => this.load(Number((e.target as HTMLSelectElement).value))}
                >
                  {weeks.map((w) => (
                    <option value={String(w.id)}>{w.name}</option>
                  ))}
                </select>
                <span className={'FantasyLineup-count' + (count > league.starters ? ' is-over' : '')}>
                  {t('count', { count, starters: league.starters })}
                </span>
              </div>

              {teams.length ? (
                <ul className={'FantasyLineup' + (this.loading ? ' is-loading' : '')}>{teams.map((team) => this.row(team))}</ul>
              ) : (
                <p className="FantasyPage-intro">{t('empty')}</p>
              )}

              <p className="helpText">{t('help', { starters: league.starters })}</p>
              <Button
                className="Button Button--primary"
                type="submit"
                loading={this.saving}
                disabled={this.saving || !changed || count > league.starters}
              >
                {t('save')}
              </Button>
            </form>
          )}
        </div>
      </div>
    );
  }

  row(team: LineupTeam) {
    const game = team.game;
    const locked = !!game?.locked;
    const on = this.chosen.has(team.id);

    return (
      <li className={'FantasyLineup-row' + (on ? ' is-starting' : '') + (locked ? ' is-locked' : '')} key={team.id}>
        {team.logo ? (
          <img className="FantasyLineup-crest" src={crestUrl(team.logo, 36)} width={36} height={36} alt="" loading="lazy" decoding="async" />
        ) : (
          <span className="FantasyLineup-crest FantasyLineup-crest--none" aria-hidden="true">
            {team.name.slice(0, 1)}
          </span>
        )}
        <span className="FantasyLineup-team">
          <b>{team.name}</b>
          <small>{this.game(game)}</small>
        </span>
        {locked ? <Icon name="fas fa-lock" className="FantasyLineup-lock" title={extractText(t('locked'))} /> : null}
        <Switch
          state={on}
          disabled={locked}
          onchange={(value: boolean) => {
            value ? this.chosen.add(team.id) : this.chosen.delete(team.id);
            // Starting one more than allowed swaps out nothing on its own: the counter turns red and Save waits.
          }}
        >
          {t(on ? 'starting' : 'bench')}
        </Switch>
      </li>
    );
  }

  /** "vs Bulls · Sat 3:30 PM", "at Bulls · Final 28–14", or "No game this week". */
  game(game: LineupGame | null) {
    if (!game) return t('bye');

    const where = t(game.home ? 'vs' : 'at', { team: game.opponent.name });
    if (game.beforeDraft) return [where, ' · ', t('before_draft')];
    if (game.status === 'finished' && game.score) return [where, ' · ', t('final', { for: game.score[0], against: game.score[1] })];
    if (game.status === 'in_progress') return [where, ' · ', t('live')];
    if (!game.kickoff) return where;

    const when = new Date(game.kickoff).toLocaleString(undefined, { weekday: 'short', hour: 'numeric', minute: '2-digit' });

    return [where, ' · ', when];
  }

  save(e: Event) {
    e.preventDefault();
    this.saving = true;
    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/fantasy/leagues/${encodeURIComponent(this.data!.league.slug)}/lineup`,
        body: { weekId: this.data!.week, teamIds: [...this.chosen] },
      })
      .then(() => {
        this.saving = false;
        this.saved = new Set(this.chosen);
        app.alerts.show({ type: 'success' }, t('saved'));
        m.redraw();
      })
      .catch((err) => {
        this.saving = false;
        m.redraw();
        throw err;
      });
  }
}
