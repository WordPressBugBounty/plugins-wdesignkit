<?php
/**
 * Loader for the PHP importer service layer.
 *
 * Single require point so the plugin's dependency loader gains one line rather than six.
 *
 * Every file below defines classes only — nothing is instantiated at load time, so requiring
 * this has no effect on any existing request. The browser importer does not call any of it;
 * the wizard's path through admin-ajax is unchanged.
 *
 * Two hooks are registered at the bottom: the deferred-thumbnail cron, and the site-side
 * import queue, which has to be listening on cron and admin_init before either fires.
 *
 * Order matters slightly: the session is referenced by the AI content store, and the runner
 * references all of them.
 *
 * @package Wdesignkit
 * @since   2.6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$wdkit_import_dir = __DIR__ . '/';

require_once $wdkit_import_dir . 'class-wdkit-import-log.php';
require_once $wdkit_import_dir . 'class-wdkit-import-css.php';
require_once $wdkit_import_dir . 'class-wdkit-import-errors.php';
require_once $wdkit_import_dir . 'class-wdkit-import-session.php';
require_once $wdkit_import_dir . 'class-wdkit-import-context.php';
require_once $wdkit_import_dir . 'class-wdkit-ai-content.php';
require_once $wdkit_import_dir . 'class-wdkit-import-media.php';
require_once $wdkit_import_dir . 'class-wdkit-import-team-images.php';
require_once $wdkit_import_dir . 'class-wdkit-import-dependencies.php';
require_once $wdkit_import_dir . 'class-wdkit-import-globals.php';
require_once $wdkit_import_dir . 'class-wdkit-import-settings.php';
require_once $wdkit_import_dir . 'class-wdkit-import-navigation.php';
require_once $wdkit_import_dir . 'class-wdkit-import-taxonomy.php';
require_once $wdkit_import_dir . 'class-wdkit-import-products.php';
require_once $wdkit_import_dir . 'class-wdkit-import-cleanup.php';
require_once $wdkit_import_dir . 'class-wdkit-import-reset.php';
require_once $wdkit_import_dir . 'class-wdkit-page-importer.php';
require_once $wdkit_import_dir . 'class-wdkit-import-posts.php';
require_once $wdkit_import_dir . 'class-wdkit-import-runner.php';
require_once $wdkit_import_dir . 'class-wdkit-import-bridge.php';
require_once $wdkit_import_dir . 'class-wdkit-import-wizard.php';
require_once $wdkit_import_dir . 'class-wdkit-import-remote.php';
require_once $wdkit_import_dir . 'class-wdkit-import-job-executor.php';

/* The site-side import queue. The one exception to "no hooks at load time" above: its whole
 * job is to run on cron and on admin_init, so it has to be wired up before either fires. It
 * registers a schedule and two actions and does nothing else until this site has been
 * registered for imports. */
if ( class_exists( 'Wdkit_Import_Remote' ) ) {
	Wdkit_Import_Remote::init();
}

/* The other half: runs a claimed job through wdkit_create_full_site. Listens for the action
 * the transport fires, so it has to be wired up before any cron tick can claim anything. */
if ( class_exists( 'Wdkit_Import_Job_Executor' ) ) {
	Wdkit_Import_Job_Executor::init();
}

/* Page warm-up, deferred out of the import request. Doing it inline deadlocked against the
 * worker the import itself was holding — see the note in stage_finalize(). The event carries
 * every imported page's URL; one scheduled by an older version carries none and warms the
 * front page alone, as it always did. */
add_action(
	'wdkit_warm_imported_front',
	function ( $urls = array() ) {
		if ( class_exists( 'Wdkit_Import_Css' ) ) {
			$urls = is_array( $urls ) ? $urls : array();

			/* Twice, for the reason the wizard's warm-up runs twice: each page's first render
			 * records its templates and invalidates the pages warmed before it. */
			Wdkit_Import_Css::warm( $urls );
			Wdkit_Import_Css::warm( $urls );
		}
	}
);

/* Thumbnail sizes the import deliberately skipped, built afterwards by cron. */
add_action(
	'wdkit_regenerate_import_thumbnails',
	function () {
		if ( class_exists( 'Wdkit_Import_Media' ) ) {
			Wdkit_Import_Media::regenerate_pending_thumbnails();
		}
	}
);
