import Extend from 'flarum/common/extenders';
import League from './models/League';

/*
 * The fantasy pages are code-split: each loads as its own chunk when someone
 * opens it (registered on the server with `jsDirectory`).
 */
export default [
  new Extend.Store().add('fantasy-leagues', League),

  new Extend.Routes()
    .add('fantasy.index', '/fantasy', () => import('./components/FantasyIndexPage'))
    .add('fantasy.league', '/fantasy/:slug', () => import('./components/FantasyLeaguePage'))
    .add('fantasy.draft', '/fantasy/:slug/draft', () => import('./components/FantasyDraftPage'))
    .add('fantasy.lineup', '/fantasy/:slug/lineup', () => import('./components/FantasyLineupPage')),
];
