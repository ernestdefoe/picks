import app from 'flarum/admin/app';

export { default as extend } from './extend';

app.initializers.add('ernestdefoe/picks', () => {
  // Extenders run automatically via the `extend` export above.
  // bootExtensions() calls extend() on each extender when the module is loaded.
});
