import app from 'flarum/admin/app';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe-fantasy', () => {
  // The settings are declared as extenders, which Flarum applies when this
  // module loads. There is nothing to do here.
});
