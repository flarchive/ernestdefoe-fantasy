import app from 'flarum/forum/app';
import type Mithril from 'mithril';
import Page, { IPageAttrs } from 'flarum/common/components/Page';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Link from 'flarum/common/components/Link';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import extractText from 'flarum/common/utils/extractText';
import crestUrl from '../crest';
import type { DraftData, DraftClock, DraftFranchise, DraftPick, AvailableTeam } from '../types';

declare const m: any;

const t = (key: string, params: Record<string, unknown> = {}) => app.translator.trans('ernestdefoe-fantasy.forum.draft.' + key, params);

/**
 * The draft room. Snake order, one pick at a time: the franchise on the clock
 * picks, or the commissioner picks for it, and everyone else watches the board
 * fill in. It re-reads the board every few seconds while the draft is on, and
 * not at all while the tab is hidden.
 */
export default class FantasyDraftPage extends Page {
  loading = true;
  data: DraftData | null = null;
  search = '';
  picking: number | null = null;
  timer: ReturnType<typeof setInterval> | undefined;
  searchTimer: ReturnType<typeof setTimeout> | undefined;

  oninit(vnode: Mithril.Vnode<IPageAttrs, this>) {
    super.oninit(vnode);
    this.load();
    this.timer = setInterval(() => {
      if (!document.hidden && this.data?.league?.status === 'drafting' && this.picking === null) this.load();
    }, 5000);
  }

  onremove(vnode: Mithril.VnodeDOM<IPageAttrs, this>) {
    super.onremove(vnode);
    clearInterval(this.timer);
    clearTimeout(this.searchTimer);
  }

  load() {
    return app
      .request<DraftData>({
        method: 'GET',
        url: `${app.forum.attribute('apiUrl')}/fantasy/draft`,
        params: { slug: m.route.param('slug'), q: this.search },
      })
      .then((data: DraftData) => {
        const first = this.data === null;
        this.data = data;
        this.loading = false;
        if (first) app.setTitle(extractText(t('title', { league: data.league.name })));
        m.redraw();
      })
      .catch(() => {
        this.loading = false;
        m.redraw();
      });
  }

  view() {
    if (this.loading) return <LoadingIndicator />;
    if (!this.data)
      return (
        <div className="container">
          <p>{app.translator.trans('ernestdefoe-fantasy.forum.no_league')}</p>
        </div>
      );

    const { league, order, clock, history, available, viewer } = this.data;
    const byId: Record<number, DraftFranchise> = {};
    order.forEach((f) => (byId[f.id] = f));

    return (
      <div className="FantasyPage FantasyPage--draft">
        <div className="container">
          <Link className="FantasyLeague-back" href={app.route('fantasy.league', { slug: league.slug })}>
            {league.name}
          </Link>
          <h1>{t('title', { league: league.name })}</h1>

          {this.banner(league, clock, byId, viewer)}

          {league.status === 'drafting' ? (
            <div className="FantasyDraft">
              <section className="FantasyDraft-board">
                <div className="FantasyDraft-search">
                  <input
                    className="FormControl"
                    type="search"
                    value={this.search}
                    placeholder={extractText(t('search'))}
                    aria-label={extractText(t('search'))}
                    oninput={(e: InputEvent) => {
                      this.search = (e.target as HTMLInputElement).value;
                      clearTimeout(this.searchTimer);
                      this.searchTimer = setTimeout(() => this.load(), 250);
                    }}
                  />
                </div>
                {available.length ? (
                  <ul className="FantasyDraft-teams">
                    {available.map((team) => (
                      <li className="FantasyDraft-team" key={team.id}>
                        {this.crest(team.logo_path || '', team.name, 32)}
                        <span className="FantasyDraft-teamName">
                          <b>{team.name}</b>
                          {team.conference ? <small>{team.conference}</small> : null}
                        </span>
                        {viewer.canPick ? (
                          <Button
                            className="Button Button--primary Button--small"
                            loading={this.picking === team.id}
                            disabled={this.picking !== null}
                            onclick={() => this.pick(team)}
                          >
                            {t('pick')}
                          </Button>
                        ) : null}
                      </li>
                    ))}
                  </ul>
                ) : (
                  <p className="FantasyDraft-empty">{t(this.search ? 'no_match' : 'none_left')}</p>
                )}
              </section>

              <aside className="FantasyDraft-side">
                <h2>{t('order')}</h2>
                <ol className="FantasyDraft-order">
                  {order.map((f) => (
                    <li className={clock && clock.franchise === f.id ? 'is-onClock' : ''} key={f.id}>
                      <span className="FantasyDraft-orderName">
                        <b>{f.name}</b>
                        <small>{f.member}</small>
                      </span>
                      <span className="FantasyDraft-count">
                        {f.picks}/{league.rosterSize}
                      </span>
                    </li>
                  ))}
                </ol>
                {this.picks(history, byId, order.length)}
              </aside>
            </div>
          ) : (
            this.picks(history, byId, order.length)
          )}
        </div>
      </div>
    );
  }

  banner(league: DraftData['league'], clock: DraftClock | null, byId: Record<number, DraftFranchise>, viewer: DraftData['viewer']) {
    if (league.status === 'setup') {
      return <p className="FantasyDraft-banner">{t('not_started')}</p>;
    }

    if (league.status !== 'drafting' || !clock) {
      return (
        <div className="FantasyDraft-banner FantasyDraft-banner--done">
          <span>{t('done')}</span>
          {viewer.franchise ? (
            <LinkButton className="Button Button--primary" href={app.route('fantasy.lineup', { slug: league.slug })}>
              {app.translator.trans('ernestdefoe-fantasy.forum.set_lineup')}
            </LinkButton>
          ) : null}
        </div>
      );
    }

    const onClock = byId[clock.franchise];
    const mine = viewer.franchise === clock.franchise;

    return (
      <div className={'FantasyDraft-banner' + (mine ? ' FantasyDraft-banner--mine' : '')} aria-live="polite">
        <span className="FantasyDraft-round">{t('round_pick', { round: clock.round, pick: clock.pick, overall: clock.overall })}</span>
        <b>{mine ? t('your_pick') : t('on_the_clock', { franchise: onClock?.name || '' })}</b>
        {!mine && viewer.canPick ? <small>{t('picking_for', { franchise: onClock?.name || '' })}</small> : null}
      </div>
    );
  }

  /** Every pick so far, newest first, numbered the way a draft is read out: round.pick. */
  picks(history: DraftPick[], byId: Record<number, DraftFranchise>, teams: number) {
    if (!history.length) return null;

    return (
      <div className="FantasyDraft-history">
        <h2>{t('picks')}</h2>
        <ol className="FantasyDraft-picks" reversed>
          {history
            .slice()
            .reverse()
            .map((p) => (
              <li key={p.overall}>
                <span className="FantasyDraft-pickNo">
                  {Math.ceil(p.overall / teams)}.{((p.overall - 1) % teams) + 1}
                </span>
                {this.crest(p.team.logo, p.team.name, 22)}
                <span className="FantasyDraft-pickTeam">{p.team.name}</span>
                <small>{byId[p.franchise]?.name}</small>
              </li>
            ))}
        </ol>
      </div>
    );
  }

  crest(logo: string, name: string, px: number) {
    return logo ? (
      <img className="FantasyDraft-crest" src={crestUrl(logo, px)} width={px} height={px} alt="" loading="lazy" decoding="async" />
    ) : (
      <span className="FantasyDraft-crest FantasyDraft-crest--none" style={{ width: px + 'px', height: px + 'px' }} aria-hidden="true">
        {name.slice(0, 1)}
      </span>
    );
  }

  pick(team: AvailableTeam) {
    this.picking = team.id;
    app
      .request({
        method: 'POST',
        url: `${app.forum.attribute('apiUrl')}/fantasy/leagues/${encodeURIComponent(this.data!.league.slug)}/draft/pick`,
        body: { teamId: team.id },
      })
      .then(() => {
        this.picking = null;
        this.search = '';
        return this.load();
      })
      .catch((err) => {
        // Someone else's pick landed first: show the board as it is now.
        this.picking = null;
        this.load();
        throw err;
      });
  }
}
