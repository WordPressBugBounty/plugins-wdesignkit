const defaults = require('@wordpress/scripts/config/webpack.config');

/**
 * WDesignKit build config.
 *
 * WordPress.org builds the translation template (POT) with `wp i18n make-pot`,
 * which parses every shipped `.js` file using the Peast JS parser. Our single
 * multi-megabyte minified bundle builds an enormous AST and exhausts make-pot's
 * memory/time budget on WordPress.org — the POT fails and NO JS strings import.
 *
 * Fix (bulletproof, memory-independent): emit the app bundle as `*.min.js`.
 * `make-pot` skips `*.min.js` files by default (hard-coded exclude), so the huge
 * bundle is NEVER parsed. The translatable strings are provided separately in a
 * tiny, readable catalog — build/wdk-i18n-strings.js — generated from src/ by
 * bin/generate-i18n-strings.js right after this build (see the "build" npm
 * script). make-pot parses only that small file (well under a second, a few MB
 * of RAM), so extraction succeeds regardless of WordPress.org's limits. The
 * catalog is enqueued + registered with wp_set_script_translations(), so the
 * per-locale JSON loads every string into the shared wp.i18n store at runtime
 * and the whole React app is translated.
 */
module.exports = {
  ...defaults,
  externals: {
    react: 'React',
    'react-dom': 'ReactDOM',
  },
  output: {
    ...defaults.output,
    // App output becomes *.min.js so `wp i18n make-pot` skips it entirely.
    filename: '[name].min.js',
  },
  performance: {
    hints: false,
  },
};
