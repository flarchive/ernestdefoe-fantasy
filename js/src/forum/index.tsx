import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';

app.initializers.add('ernestdefoe-fantasy', () => {
  // Code-split: the fantasy pages load when someone opens them.
  app.routes['fantasy.index'] = { path: '/fantasy', component: () => import('./components/FantasyIndexPage') };
  app.routes['fantasy.league'] = { path: '/fantasy/:slug', component: () => import('./components/FantasyLeaguePage') };

  /*
   * 🚨 `IndexSidebar`, not `IndexPage`. Flarum 2 moved the index nav into its
   * own component, and extending the old one is not an error — the callback is
   * attached to a `navItems` nobody calls. The link was built, styled and
   * translated, and the only way to reach /fantasy was to type it.
   */
  extend(IndexSidebar.prototype, 'navItems', function (items: any) {
    items.add(
      'fantasy',
      <LinkButton href={app.route('fantasy.index')} icon="fas fa-trophy">
        {app.forum.attribute('fantasyNavLabel') || app.translator.trans('ernestdefoe-fantasy.forum.title')}
      </LinkButton>,
      -11
    );
  });
});
