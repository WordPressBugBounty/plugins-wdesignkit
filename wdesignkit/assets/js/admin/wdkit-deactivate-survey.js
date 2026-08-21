/**
 * Adds a close button to the shared deactivation dialog.
 *
 * The dialog's markup lives in includes/posimyth-sdk/class-posimyth-deactivation-survey.php, which
 * is sync-managed and must not be edited here, so the button is injected instead of printed. It is
 * scoped to `.posi-deact-p-wdesignkit` — the parent class that file documents for product-specific
 * customisation — so a sibling POSIMYTH plugin's dialog is untouched.
 *
 * Behaviour matches the two ways out the SDK already provides, Esc and a backdrop click: the dialog
 * closes and THE PLUGIN STAYS ACTIVE. That is deliberately different from "Skip & Deactivate",
 * which does deactivate. Nothing is sent either way.
 *
 * @since 2.6.4
 */
( function () {
	'use strict';

	var SELECTOR = '.posi-deact-p-wdesignkit';

	/**
	 * Close the dialog without deactivating.
	 *
	 * Mirrors the SDK's own closeModal(): it opens with .css('display','flex'), so clearing the
	 * inline display returns the element to the stylesheet's `display:none` and lets a later open
	 * set it again. Removing the node instead would break every reopen, since the SDK looks the
	 * modal up once on load.
	 *
	 * @param {HTMLElement} modal The dialog's outer .posi-deact-modal element.
	 */
	function close_modal( modal ) {
		modal.style.display = 'none';
	}

	/**
	 * Build the button and place it in the dialog.
	 *
	 * @param {HTMLElement} modal The dialog's outer .posi-deact-modal element.
	 */
	function add_close_button( modal ) {
		var dialog = modal.querySelector( '.posi-deact-dialog' );

		// Guard against running twice: the script is idempotent so a second call — a re-render, or a
		// future SDK that ships its own control — cannot stack two buttons in the corner.
		if ( ! dialog || dialog.querySelector( '.wdkit-deact-close' ) ) {
			return;
		}

		var button = document.createElement( 'button' );

		button.type = 'button';
		button.className = 'wdkit-deact-close';
		// Named for screen readers: the glyph alone announces as "times" or nothing at all.
		button.setAttribute( 'aria-label', wdkitDeactSurvey.close_label );
		button.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';

		button.addEventListener( 'click', function () {
			close_modal( modal );
		} );

		dialog.appendChild( button );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var modal = document.querySelector( SELECTOR );

		if ( modal ) {
			add_close_button( modal );
		}
	} );
}() );
