/**
 * WDesignKit — Cross-Domain Copy / Paste
 *
 * Adds explicit "WDesignKit Copy" / "WDesignKit Paste" actions for
 * Elementor, Gutenberg and Bricks. The system clipboard carries a
 * tagged JSON payload so the same content can travel between two
 * different WordPress sites.
 *
 * Native Ctrl+C / Ctrl+V is NEVER hijacked. Cross-domain actions use:
 *   - Context-menu / toolbar entries (Elementor)
 *   - Ctrl+Shift+C / Ctrl+Shift+V keyboard shortcut (all builders)
 *   - Auto-detect on the `paste` DOM event when the clipboard already
 *     carries a WDesignKit payload and the focus is on the editor canvas.
 */
(function () {
	'use strict';

	var config = window.wdkitCrossCopyPaste || {};

	if (!config.enabled) {
		return;
	}

	var FORMAT = 'wdesignkit/cross-copy-paste';
	var STORAGE_KEY = 'wdkit_cross_copy_paste_payload';
	var builders = config.builders || {};
	// Prefer the values our own localization ships with — these are always
	// present in editor contexts. Fall back to the dashboard's wdkitData
	// global if available (kept for older enqueue paths).
	var ajax = config.ajax_url || (window.wdkitData && window.wdkitData.ajax_url) || '';
	var nonce = config.kit_nonce || (window.wdkitData && window.wdkitData.kit_nonce) || '';
	// Base URL of the WDesignKit dashboard (hash-router) page. Missing
	// widgets are installed by opening its `/create/widget/:w_unique` route
	// in a hidden iframe. Fall back to deriving it from the ajax URL when
	// the localized value isn't present.
	var dashboardUrl = config.dashboard_url
		|| (ajax ? ajax.replace('admin-ajax.php', 'admin.php?page=wdesign-kit') : '');

	// ---------------------------------------------------------------
	// Generic helpers
	// ---------------------------------------------------------------

	function __(text) {
		return window.wp && wp.i18n && wp.i18n.__ ? wp.i18n.__(text, 'wdesignkit') : text;
	}

	function clone(data) {
		try {
			return JSON.parse(JSON.stringify(data));
		} catch (e) {
			return data;
		}
	}

	function isEditableTarget(target) {
		if (!target) {
			return false;
		}
		var tag = target.tagName;
		if ('INPUT' === tag || 'TEXTAREA' === tag || 'SELECT' === tag) {
			return true;
		}
		if (target.isContentEditable) {
			return true;
		}
		return false;
	}

	function payload(builder, content) {
		return JSON.stringify({
			format: FORMAT,
			version: 1,
			builder: builder,
			source: config.site || (window.location && window.location.origin) || '',
			timestamp: Date.now(),
			content: content,
		});
	}

	function parsePayload(text) {
		if (!text || typeof text !== 'string') {
			return false;
		}
		var trimmed = text.trim();
		if ('{' !== trimmed.charAt(0)) {
			return false;
		}
		try {
			var data = JSON.parse(trimmed);
			return data && data.format === FORMAT ? data : false;
		} catch (e) {
			return false;
		}
	}

	/**
	 * Build the Elementor clipboard string in the same shape used by the
	 * WDesignKit demos site so a single paste handler can consume both.
	 *
	 *   { "tpeletype": "section" | "widget" | <elType>, "tpelecode": { …element… } }
	 */
	function buildElementorClipboard(data) {
		var type = 'section';
		if (data && data.elType) {
			if ('widget' === data.elType) {
				type = 'widget';
			} else if ('container' === data.elType || 'section' === data.elType || 'column' === data.elType) {
				type = 'section';
			} else {
				type = data.elType;
			}
		}
		return JSON.stringify({
			tpeletype: type,
			tpelecode: data,
		});
	}

	/**
	 * Detect and unwrap the demo-site Elementor clipboard shape
	 * `{ tpeletype, tpelecode }`. Returns the inner element data or false.
	 */
	function parseDemoElementor(text) {
		if (!text || typeof text !== 'string') {
			return false;
		}
		var trimmed = text.trim();
		if ('{' !== trimmed.charAt(0)) {
			return false;
		}
		try {
			var obj = JSON.parse(trimmed);
			if (obj && obj.tpelecode && obj.tpelecode.elType) {
				return obj.tpelecode;
			}
		} catch (e) { }
		return false;
	}

	/**
	 * Detect raw Gutenberg block markup (the format the demos site copies
	 * and the format Gutenberg's own serializer emits). Returns the markup
	 * itself, or false if it isn't a block string.
	 */
	function parseDemoGutenberg(text) {
		if (!text || typeof text !== 'string') {
			return false;
		}

		// Strip a BOM if the clipboard handed us one (some browser /
		// iframe combinations prepend U+FEFF when reading text/plain).
		if ('﻿' === text.charAt(0)) {
			text = text.slice(1);
		}

		// .trim() is loose enough for standard whitespace; we further
		// strip non-breaking spaces and zero-width joiners that some
		// editors sneak in around copied block markup.
		var trimmed = text.replace(/^[\s ​‌‍﻿]+/, '');

		// WordPress block markup is a `<!-- wp:` comment. Some editors
		// (or copy-from-source workflows) prepend a few characters of
		// HTML — be tolerant: find the first `<!-- wp:` anywhere in the
		// leading 2 KiB and slice from there. The trailing portion is
		// kept intact for `wp.blocks.parse` to consume.
		var head = trimmed.slice(0, 2048);
		var idx = head.indexOf('<!-- wp:');
		if (-1 === idx) {
			return false;
		}
		return 0 === idx ? trimmed : trimmed.slice(idx);
	}

	// ---------------------------------------------------------------
	// WDesignKit widget detection + auto-install
	//
	// When the pasted content references custom WDesignKit widgets
	// (anything whose Elementor widgetType / Gutenberg block name
	// starts with `wb-`), those widget files have to exist locally on
	// the destination site for Elementor's `document/elements/create`
	// command to succeed — otherwise the editor throws
	// "ElementTypeNotFound: Element type not found: 'wb-xxxxx'".
	//
	// This block detects them, asks the server which are missing, and
	// auto-installs the missing ones by loading the dashboard's
	// /create/widget/:w_unique route inside a hidden iframe (which
	// re-uses all the React-side download + file-creation machinery
	// without us having to port 1500 lines of widget codegen).  Each
	// iframe posts a `wdkit_widget_create_result` message back to the
	// parent window when it finishes; we listen for that to mark
	// per-widget success / failure progress.
	// ---------------------------------------------------------------

	function extractWdkitWidgetIdsElementor(content) {
		var ids = [];
		function walk(node) {
			if (!node || typeof node !== 'object') {
				return;
			}
			if ('widget' === node.elType && 'string' === typeof node.widgetType && 0 === node.widgetType.indexOf('wb-')) {
				var id = node.widgetType.slice(3); // strip "wb-"
				if (id && -1 === ids.indexOf(id)) {
					ids.push(id);
				}
			}
			if (Array.isArray(node.elements)) {
				node.elements.forEach(walk);
			}
		}
		walk(content);
		return ids;
	}

	function extractWdkitWidgetIdsGutenberg(markup) {
		var ids = [];
		if (!markup || 'string' !== typeof markup) {
			return ids;
		}
		// Matches `<!-- wp:wdkit/wb-XXXXXXX ` — the Gutenberg block name
		// for WDesignKit custom widgets.
		var re = /<!--\s*wp:wdkit\/wb-([a-zA-Z0-9_-]+)/g;
		var m;
		while ((m = re.exec(markup)) !== null) {
			var id = m[1];
			if (id && -1 === ids.indexOf(id)) {
				ids.push(id);
			}
		}
		return ids;
	}

	// ---------------------------------------------------------------
	// Third-party (non-WDesignKit) widget / block detection
	//
	// The pasted design may reference widgets that belong to OTHER
	// plugins (e.g. "tp-heading-title" from The Plus Addons,
	// "premium-*", "eael-*", etc.). Unlike WDesignKit's own `wb-*`
	// widgets, these can NOT be auto-installed — they ship with a
	// separate plugin the user has to install themselves.
	//
	// When such a type isn't registered on the destination site:
	//   - Elementor creates the parent section/container OK, then throws
	//     `ElementTypeNotFound` asynchronously for the nested widget —
	//     so the paste "succeeds" yet the widget never appears.
	//   - Gutenberg drops the block in as an invalid "missing block"
	//     placeholder.
	//
	// To avoid that silent failure we collect every referenced type up
	// front, diff it against what's registered locally, and surface any
	// non-installable missing types in the popup so the user knows which
	// plugin to install.
	// ---------------------------------------------------------------

	/**
	 * Collect every Elementor widgetType referenced anywhere in the
	 * pasted content tree (deduped).
	 */
	function elementorCollectWidgetTypes(content) {
		var types = [];
		function walk(node) {
			if (!node || typeof node !== 'object') {
				return;
			}
			if ('widget' === node.elType && 'string' === typeof node.widgetType && node.widgetType) {
				if (-1 === types.indexOf(node.widgetType)) {
					types.push(node.widgetType);
				}
			}
			if (Array.isArray(node.elements)) {
				node.elements.forEach(walk);
			}
		}
		walk(content);
		return types;
	}

	/**
	 * Return the widget types referenced by the content that are NOT
	 * registered on this site AND are NOT WDesignKit `wb-*` widgets
	 * (those are handled by the auto-installer). These are third-party
	 * widgets the user must install themselves.
	 */
	function elementorForeignMissingTypes(content) {
		var cache = (window.elementor && elementor.widgetsCache) || {};
		// Guard: if the widget cache hasn't populated yet, skip the check
		// rather than risk a false "missing" on a perfectly valid widget.
		if (!Object.keys(cache).length) {
			return [];
		}
		return elementorCollectWidgetTypes(content).filter(function (type) {
			if (0 === type.indexOf('wb-')) {
				return false; // WDesignKit widget — installable, handled elsewhere.
			}
			return !cache[type]; // unknown to this site → genuinely missing.
		});
	}

	/**
	 * Parse the Gutenberg block markup and return the names of any block
	 * types that aren't registered on this site (excluding WDesignKit
	 * `wdkit/wb-*` blocks, which the auto-installer handles).
	 *
	 * `wp.blocks.parse` turns an unregistered block into a `core/missing`
	 * placeholder whose real name is kept in `attributes.originalName`.
	 */
	function gutenbergForeignMissingTypes(markup, wpInstance) {
		var wpi = wpInstance || window.wp;
		if (!wpi || !wpi.blocks || !wpi.blocks.parse || !markup) {
			return [];
		}
		var missing = [];
		function walk(blocks) {
			(blocks || []).forEach(function (b) {
				if (!b) {
					return;
				}
				if ('core/missing' === b.name) {
					var orig = (b.attributes && b.attributes.originalName) || '';
					if (orig && 0 !== orig.indexOf('wdkit/wb-') && -1 === missing.indexOf(orig)) {
						missing.push(orig);
					}
				}
				if (b.innerBlocks && b.innerBlocks.length) {
					walk(b.innerBlocks);
				}
			});
		}
		try {
			walk(wpi.blocks.parse(markup));
		} catch (e) { }
		return missing;
	}

	/**
	 * AJAX: ask the server which of the given widget IDs are already
	 * installed locally. Resolves to { installed: [...], missing: [{w_unique, name}, ...] }.
	 */
	function checkInstalledWidgets(ids, builder) {
		if (!ids || !ids.length || !ajax || !nonce) {
			return Promise.resolve({ installed: [], missing: [] });
		}
		var fd = new FormData();
		fd.append('action', 'wdkit_cp_check_widgets');
		fd.append('kit_nonce', nonce);
		fd.append('builder', builder);
		fd.append('widget_ids', JSON.stringify(ids));

		return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (json) {
				if (json && json.success && json.data) {
					return {
						installed: json.data.installed || [],
						missing: json.data.missing || [],
					};
				}
				return { installed: [], missing: [] };
			})
			.catch(function (err) {
				console.warn('[WDesignKit] widget check failed:', err);
				return { installed: [], missing: [] };
			});
	}

	/**
	 * Detect WDesignKit custom widgets referenced by the pasted content
	 * and report any that are NOT installed locally.
	 *
	 * Auto-install is intentionally NOT performed here for now — we only
	 * surface the missing list so the user can decide what to do.
	 *
	 * Resolves to:
	 *   { ok: true,  missing: [] }                   — nothing missing
	 *   { ok: false, missing: [{w_unique, name}] }   — list of missing widgets
	 */
	function checkRequiredWidgets(content, builder) {
		var ids = ('elementor' === builder)
			? extractWdkitWidgetIdsElementor(content)
			: extractWdkitWidgetIdsGutenberg(content);

		if (!ids.length) {
			return Promise.resolve({ ok: true, missing: [] });
		}

		return checkInstalledWidgets(ids, builder).then(function (check) {
			var missing = check.missing || [];
			return { ok: 0 === missing.length, missing: missing };
		});
	}

	// ---------------------------------------------------------------
	// Widget download (auto-install missing WDesignKit widgets)
	//
	// Each missing widget is installed by opening the dashboard's React
	// `Widget_creator` route (/create/widget/:w_unique) in a hidden iframe.
	// That route downloads the widget and writes its files using the
	// existing file-creation machinery, then posts a
	// `wdkit_widget_create_result` message back to us with the outcome.
	// The server may reject a download — e.g. a PRO widget on a free
	// account — in which case the message carries { success:false, name,
	// message } so we can surface the exact widget + reason in the popup.
	// ---------------------------------------------------------------

	/**
	 * Download a single missing widget. Always resolves (never rejects)
	 * with a popup row: { w_unique, name, status: 'done'|'fail', msg }.
	 *
	 * The actual download + file-creation is delegated to the dashboard's
	 * React `Widget_creator` route (/create/widget/:w_unique), loaded in a
	 * hidden iframe. That route re-uses all the existing widget codegen and
	 * posts a `wdkit_widget_create_result` message back with the outcome —
	 * so we don't re-implement ~1500 lines of file-creation here, and the
	 * popup can show the exact success / failure reason it reports.
	 */
	function downloadWidget(w) {
		var fallbackName = (__('Downloading Widget', 'wdesignkit'));

		var loginData = null;
		try {
			loginData = JSON.parse(window.localStorage.getItem('wdkit-login'));
		} catch (e) { }

		if (!loginData || !loginData.token) {
			return Promise.resolve({
				w_unique: w.w_unique,
				name: fallbackName,
				status: 'fail',
				msg: __('Please log in to WDesignKit to download this widget.', 'wdesignkit'),
			});
		}

		if (!dashboardUrl) {
			return Promise.resolve({
				w_unique: w.w_unique,
				name: fallbackName,
				status: 'fail',
				msg: __('Download is not available right now.', 'wdesignkit'),
			});
		}

		// Only accept messages coming from the dashboard's own origin.
		var expectedOrigin = '';
		try {
			expectedOrigin = new URL(dashboardUrl, window.location.href).origin;
		} catch (e) { }

		return new Promise(function (resolve) {
			var settled = false;
			var iframe = document.createElement('iframe');
			var timer = null;

			function cleanup() {
				window.removeEventListener('message', onMessage);
				if (timer) { clearTimeout(timer); timer = null; }
				try { iframe.parentNode && iframe.parentNode.removeChild(iframe); } catch (e) { }
			}

			function finish(row) {
				if (settled) { return; }
				settled = true;
				cleanup();
				resolve(row);
			}

			function onMessage(event) {
				if (expectedOrigin && event.origin !== expectedOrigin) {
					return;
				}
				var d = event.data;
				if (!d || 'object' !== typeof d || 'wdkit_widget_create_result' !== d.type) {
					return;
				}
				// Ignore results for a different widget (multiple installs
				// run sequentially, but be defensive about stray messages).
				if (d.w_unique && w.w_unique && String(d.w_unique) !== String(w.w_unique)) {
					return;
				}
				if (d.success) {
					finish({ w_unique: w.w_unique, name: d.name || fallbackName, status: 'done', assets: d.assets || null });
				} else {
					finish({
						w_unique: w.w_unique,
						name: d.name || fallbackName,
						status: 'fail',
						msg: d.message || __('Download failed.', 'wdesignkit'),
					});
				}
			}

			window.addEventListener('message', onMessage);

			// Fail-safe: never leave a row spinning forever if the iframe
			// never reports back (e.g. the dashboard failed to boot).
			timer = setTimeout(function () {
				finish({
					w_unique: w.w_unique,
					name: fallbackName,
					status: 'fail',
					msg: __('Download timed out. Please try again.', 'wdesignkit'),
				});
			}, 60000);

			iframe.setAttribute('aria-hidden', 'true');
			iframe.style.cssText = 'position:fixed;width:0;height:0;border:0;left:-9999px;top:-9999px;opacity:0;';
			iframe.src = dashboardUrl + '#/create/widget/' + encodeURIComponent(w.w_unique);
			document.body.appendChild(iframe);
		});
	}

	/**
	 * Download every missing widget, showing live progress in the popup
	 * (spinner per row while downloading, then check / cross). Resolves
	 * to the final array of rows so the caller can decide success vs error.
	 */
	function downloadMissingWidgets(missing) {
		// Seed rows in the "downloading" (pending) state.
		var rows = missing.map(function (w) {
			return {
				w_unique: w.w_unique,
				name: (__('Downloading Widget', 'wdesignkit')),
				status: 'pending',
			};
		});

		function renderProgress() {
			showPopup({
				status: 'loading',
				message: __('Downloading required widgets…', 'wdesignkit'),
				widgets: rows,
			});
		}

		renderProgress();

		// Download sequentially so each row flips done/fail in order and the
		// server isn't hit with a burst of parallel requests.
		var index = 0;
		function next() {
			if (index >= missing.length) {
				return Promise.resolve(rows);
			}
			var i = index++;
			return downloadWidget(missing[i]).then(function (row) {
				rows[i] = row;
				renderProgress();
				return next();
			});
		}

		return next();
	}

	// ---------------------------------------------------------------
	// Runtime widget registration (load freshly-installed widgets into the
	// live editor WITHOUT a page reload, so the paste renders them)
	//
	// Elementor: ask the editor to (re)fetch the widget config for any
	//   widget it doesn't yet have cached. This populates
	//   `elementor.widgetsCache` with the new `wb-*` type so
	//   `document/elements/create` no longer throws ElementTypeNotFound; the
	//   widget's own CSS/JS are enqueued automatically when Elementor renders
	//   it in the preview.
	//
	// Gutenberg: inject the widget's generated editor script (which calls
	//   `registerBlockType('wdkit/wb-…')`) plus its stylesheet, so the block
	//   becomes available to `insertBlocks`.
	// ---------------------------------------------------------------

	function injectScriptOnce(doc, url) {
		return new Promise(function (resolve) {
			if (!doc || !url) { resolve(false); return; }
			try {
				if (doc.querySelector('script[data-wdkit-widget-src="' + url + '"]')) {
					resolve(true);
					return;
				}
				var s = doc.createElement('script');
				s.src = url;
				s.async = false;
				s.setAttribute('data-wdkit-widget-src', url);
				s.onload = function () { resolve(true); };
				s.onerror = function () { resolve(false); };
				(doc.head || doc.documentElement).appendChild(s);
			} catch (e) {
				resolve(false);
			}
		});
	}

	function injectStylesheetOnce(doc, url) {
		try {
			if (!doc || !url) { return; }
			if (doc.querySelector('link[data-wdkit-widget-css="' + url + '"]')) { return; }
			var l = doc.createElement('link');
			l.rel = 'stylesheet';
			l.href = url;
			l.setAttribute('data-wdkit-widget-css', url);
			(doc.head || doc.documentElement).appendChild(l);
		} catch (e) { }
	}

	/**
	 * Populate elementor.widgetsCache with any widget types not yet cached
	 * (i.e. the ones we just installed). Mirrors the editor's own
	 * requestWidgetsConfig() but resolves a Promise so we can await it.
	 */
	function elementorEnsureWidgets() {
		if (!window.elementor) {
			return Promise.resolve();
		}

		return new Promise(function (resolve) {
			var settled = false;
			function done() { if (!settled) { settled = true; resolve(); } }

			try {
				var cache = elementor.widgetsCache || {};
				if (window.elementorCommon && elementorCommon.ajax && elementorCommon.ajax.addRequest) {
					var exclude = {};
					Object.keys(cache).forEach(function (name) {
						if (cache[name] && cache[name].controls) {
							exclude[name] = true;
						}
					});
					elementorCommon.ajax.addRequest('get_widgets_config', {
						data: { exclude: exclude },
						success: function (data) {
							try { elementor.addWidgetsCache(data); } catch (e) { }
							done();
						},
						error: function () { done(); },
					});
					// Safety net so a stalled request never hangs the paste.
					setTimeout(done, 10000);
					return;
				}

				// Fallback: full widget refresh (reloads only the preview canvas).
				if ('function' === typeof elementor.refreshWidgets) {
					var p = elementor.refreshWidgets();
					if (p && p.then) {
						p.then(done, done);
						setTimeout(done, 10000);
						return;
					}
				}
			} catch (e) { }

			done();
		});
	}

	function loadGutenbergWidgetAssets(assets) {
		if (!assets || !assets.js) {
			return Promise.resolve();
		}

		var docs = [document];
		try {
			var gb = findGutenbergEditorIframe();
			if (gb && gb.contentDocument) {
				docs.push(gb.contentDocument);
			}
		} catch (e) { }

		if (assets.css) {
			docs.forEach(function (doc) { injectStylesheetOnce(doc, assets.css); });
		}

		// registerBlockType must run where wp.blocks lives — the top window.
		return injectScriptOnce(document, assets.js);
	}

	/**
	 * Load every freshly-installed widget into the live editor so the
	 * subsequent paste renders it without a page reload.
	 */
	function registerInstalledWidgets(rows, builder) {
		var done = (rows || []).filter(function (r) { return r && 'done' === r.status; });
		if (!done.length) {
			return Promise.resolve();
		}

		if ('elementor' === builder) {
			return elementorEnsureWidgets();
		}

		if ('gutenberg' === builder) {
			return Promise.all(done.map(function (r) {
				return loadGutenbergWidgetAssets(r.assets);
			})).then(function () { return true; }).catch(function () { return true; });
		}

		return Promise.resolve();
	}

	/**
	 * Run the missing-widget download flow, then show a success popup (and
	 * run onAllInstalled) or an error popup naming the widget(s) that failed.
	 *
	 * @param {Array}    missing         List of { w_unique, name } from checkRequiredWidgets.
	 * @param {Function} onAllInstalled  Called when every widget downloaded OK.
	 * @param {string}   builder         'elementor' | 'gutenberg' — drives how the
	 *                                   newly installed widgets are registered live.
	 */
	function handleMissingWidgets(missing, onAllInstalled, builder) {
		return downloadMissingWidgets(missing).then(function (rows) {
			var failed = rows.filter(function (r) { return 'fail' === r.status; });

			if (!failed.length) {
				// Register / enqueue the freshly installed widgets in the live
				// editor so the paste renders them WITHOUT a page reload.
				showPopup({
					status: 'loading',
					message: __('Preparing widgets…', 'wdesignkit'),
				});
				return registerInstalledWidgets(rows, builder).then(function () {
					showPopup({
						status: 'success',
						message: __('Required widgets downloaded successfully.', 'wdesignkit'),
					});
					if ('function' === typeof onAllInstalled) {
						return onAllInstalled();
					}
					return true;
				});
			}

			// One failure → show its name + reason directly. Multiple → list
			// each failed widget as a row with its reason.
			if (1 === failed.length) {
				showPopup({
					status: 'error',
					message: failed[0].name,
					description: failed[0].msg || __('This widget could not be downloaded.', 'wdesignkit'),
				});
			} else {
				showPopup({
					status: 'error',
					message: __('Some widgets could not be downloaded.', 'wdesignkit'),
					widgets: failed,
				});
			}
			return false;
		});
	}

	/**
	 * Show an error popup naming the third-party widget(s) the copied
	 * content needs but this site doesn't have. These come from other
	 * plugins (The Plus Addons, Essential Addons, etc.) and can't be
	 * auto-installed by WDesignKit — the user has to install/activate the
	 * source plugin themselves. Called instead of pasting so we never show
	 * a false "success" while the widget silently fails to render.
	 *
	 * @param {string[]} types    Missing widget / block type names.
	 * @param {string}   builder  'elementor' | 'gutenberg' (for the label).
	 */
	function showMissingPluginPopup(types, builder) {
		var rows = (types || []).map(function (t) {
			return {
				w_unique: t,
				name: t,
				status: 'fail',
				msg: __('Plugin not active', 'wdesignkit'),
			};
		});

		var single = 1 === rows.length;
		var label = ('gutenberg' === builder)
			? __('block', 'wdesignkit')
			: __('widget', 'wdesignkit');

		showPopup({
			status: 'error',
			message:  __('Unable to Paste Content', 'wdesignkit'),
			description: __('This content depends on a widget or plugin that is not installed or activated on this website. Install and activate the required plugin, then try pasting again.', 'wdesignkit'),
			widgets: rows,
		});
	}

	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', 'readonly');
		ta.style.position = 'fixed';
		ta.style.top = '0';
		ta.style.left = '-9999px';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
		} catch (e) { }
		document.body.removeChild(ta);
		return true;
	}

	function writeClipboard(text) {
		// Always save to localStorage first — this is the reliable cross-iframe
		// fallback, especially critical for Bricks which runs inside an iframe
		// where navigator.clipboard.readText() requires document focus.
		try {
			window.localStorage.setItem(STORAGE_KEY, text);
		} catch (e) { }

		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text).catch(function () {
				return fallbackCopy(text);
			});
		}

		return Promise.resolve(fallbackCopy(text));
	}

	// True for any clipboard text WDesignKit knows how to consume — both
	// the new demo formats and the legacy wrapped payload.
	function isRecognisedClipboard(text) {
		return !!(text && (
			parsePayload(text)
			|| parseDemoElementor(text)
			|| parseDemoGutenberg(text)
		));
	}

	function readClipboard() {
		var local = '';
		try {
			local = window.localStorage.getItem(STORAGE_KEY) || '';
		} catch (e) { }

		if (navigator.clipboard && navigator.clipboard.readText) {
			return navigator.clipboard.readText().then(function (text) {
				// Always prefer the live clipboard when it carries something we
				// recognise — it is the most recently copied item. Stale
				// localStorage from an earlier copy should never shadow a
				// freshly-copied demo widget / block.
				if (isRecognisedClipboard(text)) {
					return text;
				}
				if (isRecognisedClipboard(local)) {
					return local;
				}
				return text || local;
			}).catch(function () {
				// clipboard.readText() failed (e.g. document not focused in iframe)
				// — silently fall back to localStorage which always works.
				return local;
			});
		}

		return Promise.resolve(local);
	}

	/**
	 * Bricks-specific clipboard read.
	 *
	 * Bricks runs inside an iframe. navigator.clipboard.readText() throws
	 * "Document is not focused" in that context, so we read ONLY from
	 * localStorage (which was written by writeClipboard() during copy).
	 * This completely avoids the Clipboard API permission issue.
	 */
	function readClipboardBricks() {
		var local = '';
		try {
			local = window.localStorage.getItem(STORAGE_KEY) || '';
		} catch (e) { }

		// Always try the live clipboard first so a freshly-copied element is
		// never shadowed by a stale localStorage entry.  localStorage is the
		// fallback for when the Clipboard API is blocked (e.g. iframe without
		// document focus) — NOT the primary source.
		var sources = [];

		try {
			var iframe = (typeof bricksGetIframe === 'function') ? bricksGetIframe() : null;
			if (iframe && iframe.contentWindow && iframe.contentWindow.navigator && iframe.contentWindow.navigator.clipboard && iframe.contentWindow.navigator.clipboard.readText) {
				sources.push(function () { return iframe.contentWindow.navigator.clipboard.readText(); });
			}
		} catch (e) { }

		try {
			if (navigator && navigator.clipboard && navigator.clipboard.readText) {
				sources.push(function () { return navigator.clipboard.readText(); });
			}
		} catch (e) { }

		if (!sources.length) {
			// No Clipboard API reachable at all — use localStorage as-is.
			return Promise.resolve(local);
		}

		var read = sources.reduce(function (chain, fn) {
			return chain.then(function (val) {
				if (val) return val;
				return fn().catch(function () { return ''; });
			});
		}, Promise.resolve(''));

		return read.then(function (text) {
			// Prefer a valid live clipboard payload; otherwise fall back to localStorage.
			if (text && (parsePayload(text) || looksLikeBricksClipboard(text))) {
				return text;
			}
			return local || text;
		});
	}

	// ---------------------------------------------------------------
	// Feedback popup (modal)
	//
	// Replaces the previous top-right toast notification. Mirrors the
	// "widget downloading / success" popup pattern used elsewhere in the
	// plugin so users get a familiar centred modal with backdrop, status
	// icon, message and close button.
	//
	// API:
	//   showPopup({ status: 'loading'|'success'|'warning'|'error', message })
	//   hidePopup()
	//   notify(message, type)  → maps to showPopup() for backwards compat
	// ---------------------------------------------------------------

	var POPUP_ID = 'wdkit-cross-popup';
	var POPUP_STYLE_ID = 'wdkit-cross-popup-style';
	var popupAutoCloseTimer = null;

	function injectPopupStyles() {
		if (document.getElementById(POPUP_STYLE_ID)) {
			return;
		}
		var style = document.createElement('style');
		style.id = POPUP_STYLE_ID;
		style.textContent = [
			'.wdkit-cross-popup-overlay{position:fixed;inset:0;background:rgba(15,15,20,.55);z-index:2147483647;display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .2s ease;font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;}',
			'.wdkit-cross-popup-overlay.is-visible{opacity:1;}',
			'.wdkit-cross-popup-modal{background:#fff;border-radius:12px;padding:36px 40px 32px;min-width:360px;max-width:520px;width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;align-items:center;gap:18px;position:relative;transform:scale(.94);transition:transform .2s ease;max-height:80vh;overflow-y:auto;}',
			'.wdkit-cross-popup-overlay.is-visible .wdkit-cross-popup-modal{transform:scale(1);}',
			'.wdkit-cross-popup-close{position:absolute;top:10px;right:12px;background:transparent;border:0;font-size:22px;line-height:1;cursor:pointer;color:#7a7a85;padding:4px 8px;border-radius:4px;}',
			'.wdkit-cross-popup-close:hover{background:#f4f4f6;color:#020202;}',
			'.wdkit-cross-popup-icon{display:flex;align-items:center;justify-content:center;width:64px;height:64px;}',
			'.wdkit-cross-popup-message{margin:0;color:#020202;font-size:18px;font-weight:600;text-align:center;line-height:1.4;}',
			'.wdkit-cross-popup-desc{margin:0;color:#5a5a66;font-size:14px;font-weight:400;text-align:center;line-height:1.5;}',
			'.wdkit-cross-popup-spinner{width:58px;height:58px;border:4px solid #FCE8F1;border-top-color:#C22076;border-radius:50%;animation:wdkitCrossSpin 1s linear infinite;}',
			'@keyframes wdkitCrossSpin{to{transform:rotate(360deg);}}',
			/* widget list */
			'.wdkit-cross-popup-widgets{width:100%;display:flex;flex-direction:column;gap:8px;margin-top:4px;border-top:1px solid #efeff2;padding-top:14px;}',
			'.wdkit-cross-popup-wrow{display:flex;align-items:center;gap:10px;padding:8px 10px;background:#fafafb;border:1px solid #efeff2;border-radius:8px;font-size:14px;color:#1d2327;}',
			'.wdkit-cross-popup-wrow .wdkit-wrow-icon{width:18px;height:18px;flex:0 0 18px;display:flex;align-items:center;justify-content:center;}',
			'.wdkit-cross-popup-wrow .wdkit-wrow-spin{width:14px;height:14px;border:2px solid #FCE8F1;border-top-color:#C22076;border-radius:50%;animation:wdkitCrossSpin .9s linear infinite;}',
			'.wdkit-cross-popup-wrow .wdkit-wrow-name{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
			'.wdkit-cross-popup-wrow .wdkit-wrow-msg{color:#b25e09;font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}',
			'.wdkit-cross-popup-wrow.is-done{background:#f1fbf4;border-color:#cdebd6;}',
			'.wdkit-cross-popup-wrow.is-fail{background:#fdeeee;border-color:#f3cccc;}',
			/* skip / continue button — matches `.wkit-btn-class` (black) used across the WDesignKit plugin */
			'.wdkit-cross-popup-actions{display:flex;gap:10px;margin-top:6px;}',
			'.wdkit-cross-popup-btn{display:inline-flex;align-items:center;justify-content:center;background:#020202;color:#fff;border:1px solid #020202;padding:8px 22px;border-radius:4px;font-size:14px;font-weight:500;text-transform:capitalize;cursor:pointer;transition:background .2s ease,border-color .2s ease,color .2s ease;line-height:1.4;}',
			'.wdkit-cross-popup-btn:hover{background:#282828;border-color:#282828;color:#fff;}',
			'.wdkit-cross-popup-btn:focus{outline:none;color:#fff;}',
			'.wdkit-cross-popup-btn.is-ghost{background:transparent;color:#020202;border-color:#d4d4d8;}',
			'.wdkit-cross-popup-btn.is-ghost:hover{background:#f4f4f6;color:#020202;border-color:#d4d4d8;}',
		].join('');
		document.head.appendChild(style);
	}

	function buildPopupIcon(status) {
		if ('loading' === status) {
			return '<div class="wdkit-cross-popup-spinner" role="status" aria-label="' + escapeAttr(__('Loading', 'wdesignkit')) + '"></div>';
		}
		if ('success' === status) {
			return '<svg width="56" height="56" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">'
				+ '<path d="M40 20C40 31.0457 31.0457 40 20 40C8.95431 40 0 31.0457 0 20C0 8.95431 8.95431 0 20 0C31.0457 0 40 8.95431 40 20Z" fill="#FCE8F1"/>'
				+ '<path d="M20.1429 3C10.6907 3 3 10.6907 3 20.1429C3 29.5951 10.6907 37.2857 20.1429 37.2857C29.5951 37.2857 37.2857 29.5951 37.2857 20.1429C37.2857 10.6907 29.5951 3 20.1429 3ZM29.724 15.6316L18.768 26.5016C18.1235 27.1461 17.0924 27.189 16.4049 26.5446L10.6047 21.2599C9.91729 20.6155 9.87433 19.5414 10.4758 18.8539C11.1203 18.1665 12.1944 18.1235 12.8818 18.768L17.4791 22.9785L27.275 13.1826C27.9624 12.4952 29.0365 12.4952 29.724 13.1826C30.4114 13.87 30.4114 14.9441 29.724 15.6316Z" fill="#C22076"/>'
				+ '</svg>';
		}
		if ('warning' === status) {
			return '<svg width="56" height="56" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">'
				+ '<circle cx="20" cy="20" r="20" fill="#FFF4E0"/>'
				+ '<path d="M20 11v11M20 27.5v.5" stroke="#B25E09" stroke-width="3.2" stroke-linecap="round"/>'
				+ '</svg>';
		}
		// error / fallback
		return '<svg width="56" height="56" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">'
			+ '<circle cx="20" cy="20" r="20" fill="#FDEAEA"/>'
			+ '<path d="M14 14l12 12M26 14L14 26" stroke="#C13030" stroke-width="3.2" stroke-linecap="round"/>'
			+ '</svg>';
	}

	function hidePopup() {
		if (popupAutoCloseTimer) {
			clearTimeout(popupAutoCloseTimer);
			popupAutoCloseTimer = null;
		}
		var existing = document.getElementById(POPUP_ID);
		if (!existing) {
			return;
		}
		existing.classList.remove('is-visible');
		setTimeout(function () {
			try { existing.parentNode && existing.parentNode.removeChild(existing); } catch (e) { }
		}, 200);
	}

	function buildWidgetRowHtml(row) {
		var statusIcon;
		if ('done' === row.status) {
			statusIcon = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none"><path d="M5 12.5l4.5 4.5L19 7.5" stroke="#1a8754" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		} else if ('fail' === row.status) {
			statusIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M6 6l12 12M18 6L6 18" stroke="#c13030" stroke-width="2.8" stroke-linecap="round"/></svg>';
		} else {
			statusIcon = '<div class="wdkit-wrow-spin"></div>';
		}
		var msgHtml = row.msg
			? '<span class="wdkit-wrow-msg" title="' + escapeAttr(row.msg) + '">' + escapeHtml(row.msg) + '</span>'
			: '';
		var cls = 'wdkit-cross-popup-wrow';
		if ('done' === row.status) cls += ' is-done';
		if ('fail' === row.status) cls += ' is-fail';
		return '<div class="' + cls + '">'
			+ '<span class="wdkit-wrow-icon">' + statusIcon + '</span>'
			+ '<span class="wdkit-wrow-name">' + escapeHtml(row.name || row.w_unique) + '</span>'
			+ msgHtml
			+ '</div>';
	}

	function escapeHtml(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	function escapeAttr(s) { return escapeHtml(s); }

	function showPopup(opts) {
		opts = opts || {};
		var status = opts.status || 'success';
		var message = opts.message || '';
		var description = opts.description || '';
		var widgets = Array.isArray(opts.widgets) ? opts.widgets : null;
		var actions = Array.isArray(opts.actions) ? opts.actions : null;
		// Auto-close only for simple success/warning/error popups without
		// a widget list or custom actions. A loading popup OR a popup
		// holding interactive content must stay open until explicitly
		// dismissed (otherwise the user loses track of progress / actions).
		var autoClose = ('loading' !== status) && !widgets && !actions;

		injectPopupStyles();

		// Reuse the existing node when possible so back-to-back state changes
		// (loading → success) animate smoothly instead of flickering.
		var overlay = document.getElementById(POPUP_ID);
		var modal, iconWrap, msgEl, descEl, widgetsEl, actionsEl;

		if (overlay) {
			modal = overlay.querySelector('.wdkit-cross-popup-modal');
			iconWrap = overlay.querySelector('.wdkit-cross-popup-icon');
			msgEl = overlay.querySelector('.wdkit-cross-popup-message');
			descEl = overlay.querySelector('.wdkit-cross-popup-desc');
			widgetsEl = overlay.querySelector('.wdkit-cross-popup-widgets');
			actionsEl = overlay.querySelector('.wdkit-cross-popup-actions');
		} else {
			overlay = document.createElement('div');
			overlay.className = 'wdkit-cross-popup-overlay';
			overlay.id = POPUP_ID;

			modal = document.createElement('div');
			modal.className = 'wdkit-cross-popup-modal';

			var closeBtn = document.createElement('button');
			closeBtn.type = 'button';
			closeBtn.className = 'wdkit-cross-popup-close';
			closeBtn.setAttribute('aria-label', __('Close', 'wdesignkit'));
			closeBtn.innerHTML = '&times;';
			closeBtn.addEventListener('click', hidePopup);
			modal.appendChild(closeBtn);

			iconWrap = document.createElement('div');
			iconWrap.className = 'wdkit-cross-popup-icon';
			modal.appendChild(iconWrap);

			msgEl = document.createElement('p');
			msgEl.className = 'wdkit-cross-popup-message';
			modal.appendChild(msgEl);

			descEl = document.createElement('p');
			descEl.className = 'wdkit-cross-popup-desc';
			descEl.style.display = 'none';
			modal.appendChild(descEl);

			widgetsEl = document.createElement('div');
			widgetsEl.className = 'wdkit-cross-popup-widgets';
			widgetsEl.style.display = 'none';
			modal.appendChild(widgetsEl);

			actionsEl = document.createElement('div');
			actionsEl.className = 'wdkit-cross-popup-actions';
			actionsEl.style.display = 'none';
			modal.appendChild(actionsEl);

			overlay.appendChild(modal);
			document.body.appendChild(overlay);

			// Click backdrop to close (only for non-loading states).
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay && !modal.dataset.busy) {
					hidePopup();
				}
			});

			// Esc key to close — only when nothing is ongoing
			// (modal.dataset.busy is '' for success/warning/error popups
			// without widgets/actions). Loading popups and popups with
			// in-progress widget rows / pending action buttons stay open
			// until they finish or the user clicks an explicit action.
			//
			// Listener is attached to the document so it works regardless
			// of which element currently has focus (the editor canvas
			// often takes focus away from the modal).
			//
			// Capture phase + isVisible guard prevents the handler from
			// firing when the popup is hidden — otherwise Esc inside the
			// Elementor / Gutenberg editor would close other things.
			document.addEventListener('keydown', function (e) {
				if ('Escape' !== e.key && 27 !== e.keyCode) {
					return;
				}
				if (!overlay.classList.contains('is-visible')) {
					return;
				}
				if (modal.dataset.busy) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				hidePopup();
			}, true);
		}

		iconWrap.innerHTML = buildPopupIcon(status);
		msgEl.textContent = message;
		if (description) {
			descEl.textContent = description;
			descEl.style.display = '';
		} else {
			descEl.textContent = '';
			descEl.style.display = 'none';
		}
		modal.dataset.busy = ('loading' === status || widgets || actions) ? '1' : '';

		// Render widget list (or hide).
		if (widgets && widgets.length) {
			widgetsEl.innerHTML = widgets.map(buildWidgetRowHtml).join('');
			widgetsEl.style.display = '';
		} else {
			widgetsEl.innerHTML = '';
			widgetsEl.style.display = 'none';
		}

		// Render action buttons (or hide).
		actionsEl.innerHTML = '';
		if (actions && actions.length) {
			actions.forEach(function (act) {
				var btn = document.createElement('button');
				btn.type = 'button';
				btn.className = 'wdkit-cross-popup-btn' + (act.ghost ? ' is-ghost' : '');
				btn.textContent = act.label || '';
				btn.addEventListener('click', function () {
					if ('function' === typeof act.onClick) {
						try { act.onClick(); } catch (e) { console.error('[WDesignKit]', e); }
					}
					if (act.closeAfter !== false) {
						hidePopup();
					}
				});
				actionsEl.appendChild(btn);
			});
			actionsEl.style.display = '';
		} else {
			actionsEl.style.display = 'none';
		}

		// Force layout then toggle the class so the transition fires even on
		// the first paint.
		requestAnimationFrame(function () {
			overlay.classList.add('is-visible');
		});

		if (popupAutoCloseTimer) {
			clearTimeout(popupAutoCloseTimer);
			popupAutoCloseTimer = null;
		}
		if (autoClose) {
			popupAutoCloseTimer = setTimeout(hidePopup, 2500);
		}
	}

	// Backwards-compatible facade so existing notify(message, type) calls
	// surface as popup states without rewriting every call site.
	function notify(message, type) {
		var status = 'success';
		if ('warning' === type) status = 'warning';
		else if ('error' === type) status = 'error';
		else if ('loading' === type) status = 'loading';
		try { showPopup({ status: status, message: message }); } catch (e) { }
	}


	// ---------------------------------------------------------------
	// Elementor
	// ---------------------------------------------------------------

	var elementorLastContext = null;

	function elementorRenewIds(element) {
		if (!element || typeof element !== 'object') {
			return element;
		}

		if (window.elementorCommon && elementorCommon.helpers && elementorCommon.helpers.getUniqueId) {
			element.id = elementorCommon.helpers.getUniqueId();
		} else {
			element.id = Math.random().toString(36).slice(2, 10);
		}

		if (Array.isArray(element.elements)) {
			element.elements = element.elements.map(elementorRenewIds);
		}

		return element;
	}

	function resolveElementorTarget(context) {
		// Normalise the many shapes we may receive to { view, container }.
		var ctx = context || elementorLastContext || null;

		if (!ctx) {
			// Fall back to the currently-selected element in Elementor.
			if (window.elementor && elementor.selection && elementor.selection.getElements) {
				var selected = elementor.selection.getElements();
				if (selected && selected[0]) {
					ctx = selected[0];
				}
			}
		}

		if (!ctx) {
			return { view: null, container: null };
		}

		// Already normalised
		if (ctx.view || ctx.container) {
			var view = ctx.view || null;
			var container = ctx.container || (view && (view.container || (view.getContainer && view.getContainer())));
			return { view: view, container: container };
		}

		// ctx is likely a View
		var v = ctx;
		var c = v.container || (v.getContainer && v.getContainer()) || null;
		return { view: v, container: c };
	}

	function elementorGetSelectionData(context) {
		var target = resolveElementorTarget(context);
		var view = target.view;
		var model = view && view.model ? view.model : null;

		if (!model && target.container && target.container.model) {
			model = target.container.model;
		}

		if (!model || !model.toJSON) {
			return false;
		}

		return model.toJSON({ remove: ['default'] });
	}

	function elementorImportRemote(content) {
		// Re-generate IDs and import media on the server when possible.
		if (!ajax || !nonce) {
			console.warn('[WDesignKit] No wdkitData ajax/nonce — falling back to client-side ID regen.');
			return Promise.resolve(elementorRenewIds(clone(content)));
		}

		var fd = new FormData();
		fd.append('action', 'wdkit_cp_media_import');
		fd.append('kit_nonce', nonce);
		fd.append('copy_content', JSON.stringify(content));

		return fetch(ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				if (json && json.success && json.data && json.data[0]) {
					return json.data[0];
				}
				console.warn('[WDesignKit] Media import returned no data, using client-side fallback.', json);
				return elementorRenewIds(clone(content));
			})
			.catch(function (err) {
				console.warn('[WDesignKit] Media import request failed, using client-side fallback.', err);
				return elementorRenewIds(clone(content));
			});
	}

	function elementorCopy(context) {
		var data = elementorGetSelectionData(context || elementorLastContext);

		if (!data) {
			notify(__('Select an element to copy.', 'wdesignkit'), 'warning');
			return Promise.resolve(false);
		}

		// Emit the demo-site format `{ tpeletype, tpelecode }` so a single
		// copy works for both cross-domain paste and pastes originating
		// from the WDesignKit demos library.
		return writeClipboard(buildElementorClipboard(data)).then(function () {
			notify(__('Content copied for cross-domain use.', 'wdesignkit'), 'success');
			return true;
		});
	}

	function pickElementorInsertContainer(container, item) {
		// Decide where to put the new element so it lands as a sibling of the
		// right-clicked element when types match, or as a child when the
		// right-clicked element naturally accepts that type.
		if (!container) {
			return null;
		}

		var sourceType = container.model && container.model.get ? container.model.get('elType') : '';
		var itemType = item && item.elType ? item.elType : '';

		// Widget can never have children — always insert into parent.
		if ('widget' === sourceType) {
			return container.parent || container;
		}

		// Pasting a section into another section → sibling (parent = document).
		if ('section' === itemType && 'section' === sourceType) {
			return container.parent || container;
		}

		// Pasting a top-level container into another container → sibling.
		if ('container' === itemType && 'container' === sourceType) {
			return container.parent || container;
		}

		// Everything else: drop inside the right-clicked element.
		return container;
	}

	/**
	 * Detect Elementor's "ElementTypeNotFound" — thrown when the pasted
	 * model references a widget type not registered on this site (common
	 * when copying from the WDesignKit demos which use custom widgets).
	 */
	function isElementTypeNotFoundError(err) {
		if (!err) {
			return false;
		}
		if ('ElementTypeNotFound' === err.name) {
			return true;
		}
		var msg = (err.message || String(err) || '');
		return /ElementTypeNotFound|Element type not found/i.test(msg);
	}

	/**
	 * Pull the missing widget id out of the error message — Elementor
	 * formats it as: `Element type not found: 'wb-qzftow25'`.
	 */
	function extractMissingElementType(err) {
		if (!err) {
			return '';
		}
		var msg = (err.message || String(err) || '');
		var m = msg.match(/['"]([^'"]+)['"]/);
		return m ? m[1] : '';
	}

	function elementorInsert(item, context) {
		var target = resolveElementorTarget(context);
		var view = target.view;
		var container = target.container;
		var lastError = null;

		// Special case: section/container pasted on the empty canvas.
		if (!container && view && view.addChildElement && ('section' === item.elType || 'container' === item.elType || view.isCollectionView)) {
			try {
				view.addChildElement(item);
				return { ok: true };
			} catch (e) {
				lastError = e;
				console.error('[WDesignKit]', e);
				// Missing widget type is fatal — the fallback paths will
				// throw the same error, so surface it immediately.
				if (isElementTypeNotFoundError(e)) {
					return { ok: false, error: e };
				}
			}
		}

		var insertInto = pickElementorInsertContainer(container, item);

		if (insertInto && window.$e && $e.run) {
			try {
				$e.run('document/elements/create', {
					container: insertInto,
					model: item,
				});
				return { ok: true };
			} catch (e) {
				lastError = e;
				console.error('[WDesignKit] elements/create failed:', e);
				if (isElementTypeNotFoundError(e)) {
					return { ok: false, error: e };
				}
			}
		}

		// Last resort — append to preview / document.
		try {
			if (window.elementor && elementor.getPreviewView && elementor.getPreviewView().addChildElement) {
				elementor.getPreviewView().addChildElement(item);
				return { ok: true };
			}
		} catch (e) {
			lastError = e;
			console.error('[WDesignKit] preview addChildElement failed:', e);
		}

		return { ok: false, error: lastError };
	}

	/**
	 * Run the actual import + insert step. Split out of elementorPaste
	 * so it can be invoked directly, or after the required widgets have
	 * finished downloading (see handleMissingWidgets).
	 */
	function elementorPasteDoInsert(content, context) {
		showPopup({ status: 'loading', message: __('Pasting element…', 'wdesignkit') });

		return elementorImportRemote(content).then(function (item) {
			var result = elementorInsert(item, context);

			if (result && result.ok) {
				notify(__('Content pasted successfully across domains.', 'wdesignkit'), 'success');
				return true;
			}

			if (result && isElementTypeNotFoundError(result.error)) {
				var missing = extractMissingElementType(result.error);
				var msg = missing
					? __('Cannot paste: required widget "', 'wdesignkit') + missing + __('" is not installed on this site. Please install it and try again.', 'wdesignkit')
					: __('Cannot paste: one of the required widgets is not installed on this site.', 'wdesignkit');
				showPopup({ status: 'error', message: msg });
				return false;
			}

			notify(__('Could not paste — please click an existing element first.', 'wdesignkit'), 'warning');
			return false;
		}).catch(function (err) {
			console.error('[WDesignKit] paste failed:', err);
			if (isElementTypeNotFoundError(err)) {
				var missing = extractMissingElementType(err);
				var msg = missing
					? __('Cannot paste: required widget "', 'wdesignkit') + missing + __('" is not installed on this site. Please install it and try again.', 'wdesignkit')
					: __('Cannot paste: one of the required widgets is not installed on this site.', 'wdesignkit');
				showPopup({ status: 'error', message: msg });
			} else {
				notify(__('Paste failed. Please try again.', 'wdesignkit'), 'error');
			}
			return false;
		});
	}

	function elementorPaste(context) {
		return readClipboard().then(function (text) {
			// Prefer the demo-site shape `{ tpeletype, tpelecode }`; fall
			// back to the legacy WDesignKit wrapper so older clipboard
			// contents still paste correctly.
			var content = parseDemoElementor(text);

			if (!content) {
				var wrapped = parsePayload(text);
				if (wrapped && 'elementor' === wrapped.builder && wrapped.content) {
					content = wrapped.content;
				}
			}

			if (!content || !window.elementor) {
				notify(__('No Elementor content found on the clipboard.', 'wdesignkit'), 'warning');
				return false;
			}

			// Pre-step A: detect third-party widgets (e.g. "tp-heading-title"
			// from The Plus Addons) that this site doesn't have. These can't
			// be auto-installed, and if we paste anyway Elementor creates the
			// parent but throws ElementTypeNotFound for the nested widget —
			// looking like "success" while nothing renders. Stop and tell the
			// user which plugin to install instead.
			var foreignMissing = elementorForeignMissingTypes(content);
			if (foreignMissing.length) {
				showMissingPluginPopup(foreignMissing, 'elementor');
				return false;
			}

			// Pre-step B: detect any WDesignKit custom widgets (wb-*) that
			// the pasted design references but the destination site
			// doesn't have, then download them (with live popup progress)
			// before pasting. If any download fails, the error popup names
			// the widget and shows the reason.
			return checkRequiredWidgets(content, 'elementor').then(function (check) {
				if (check.ok) {
					return elementorPasteDoInsert(content, context);
				}

				return handleMissingWidgets(check.missing, function () {
					return elementorPasteDoInsert(content, context);
				}, 'elementor');
			});
		});
	}

	function initElementor() {
		if (!builders.elementor || !window.elementor || !elementor.hooks || initElementor.loaded) {
			return;
		}

		initElementor.loaded = true;

		var bindContextMenu = function () {
			['section', 'container', 'column', 'widget'].forEach(function (elementType) {
				elementor.hooks.addFilter('elements/' + elementType + '/contextMenuGroups', function (groups, context) {
					elementorLastContext = context;
					groups.push({
						name: 'wdkit_cross_copy_paste',
						actions: [
							{
								name: 'wdkit_cross_copy',
								title: __('WDesignKit Copy', 'wdesignkit'),
								icon: 'eicon-copy',
								callback: function () {
									elementorLastContext = context;
									elementorCopy(context);
								},
							},
							{
								name: 'wdkit_cross_paste',
								title: __('WDesignKit Paste', 'wdesignkit'),
								icon: 'eicon-import-export',
								callback: function () {
									elementorLastContext = context;
									elementorPaste(context);
								},
							},
						],
					});
					return groups;
				});
			});
		};

		if (elementor.on) {
			elementor.on('preview:loaded', bindContextMenu);
		} else {
			bindContextMenu();
		}

		// Track the latest selected element for keyboard / paste-event flow.
		if (elementor.channels && elementor.channels.editor && elementor.channels.editor.on) {
			elementor.channels.editor.on('section:activated container:activated column:activated widget:activated', function (childView) {
				if (childView && childView.getContainer) {
					elementorLastContext = { view: childView, container: childView.getContainer() };
				}
			});
		}
	}

	// ---------------------------------------------------------------
	// Gutenberg
	// ---------------------------------------------------------------

	function gutenbergSelectedBlocks(clientIds) {
		if (!window.wp || !wp.data || !wp.data.select) {
			return [];
		}

		var sel = wp.data.select('core/block-editor');

		if (!sel) {
			return [];
		}

		// Preferred path — explicit clientIds passed in by the SlotFill
		// render function from `BlockSettingsMenuControls` props. These
		// target the exact block whose menu the user opened, even when
		// the action originates from the List View (where the block is
		// NOT marked as "selected" in the editor store, so
		// getSelectedBlock() would return null or the wrong block).
		if (Array.isArray(clientIds) && clientIds.length && sel.getBlocksByClientId) {
			var byId = (sel.getBlocksByClientId(clientIds) || []).filter(Boolean);
			if (byId.length) {
				return byId;
			}
		}

		// Fallback — multi-selection or single-selected block in the canvas.
		var blocks = sel.getMultiSelectedBlocks ? sel.getMultiSelectedBlocks() : [];

		if ((!blocks || !blocks.length) && sel.getSelectedBlock) {
			var single = sel.getSelectedBlock();
			if (single) {
				blocks = [single];
			}
		}

		return blocks || [];
	}

	function gutenbergCopy(clientIds) {
		if (!window.wp || !wp.blocks || !wp.blocks.serialize) {
			return Promise.resolve(false);
		}

		var blocks = gutenbergSelectedBlocks(clientIds);

		if (!blocks.length) {
			notify(__('Select a block to copy.', 'wdesignkit'), 'warning');
			return Promise.resolve(false);
		}

		var serialized = wp.blocks.serialize(blocks);

		if (!serialized) {
			return Promise.resolve(false);
		}

		// Write the raw block markup (same shape the demos site copies and
		// what Gutenberg's native serializer produces) so a single paste
		// path consumes both cross-domain and demo-library content.
		return writeClipboard(serialized).then(function () {
			notify(__('Content copied for cross-domain use.', 'wdesignkit'), 'success');
			return true;
		});
	}

	function gutenbergGetWp() {
		// Prefer the editor-canvas iframe's wp instance when present —
		// in WP 6.3+ iframe-mode the canvas store lives there. Falls back
		// to the top-window wp instance.
		try {
			var iframe = findGutenbergEditorIframe();
			if (iframe && iframe.contentWindow && iframe.contentWindow.wp && iframe.contentWindow.wp.data) {
				return iframe.contentWindow.wp;
			}
		} catch (e) { }
		return window.wp;
	}

	function gutenbergInsertBlocksVia(wpInstance, content, anchorClientId) {
		if (!wpInstance || !wpInstance.blocks || !wpInstance.data) {
			return false;
		}
		try {
			var parsedBlocks = wpInstance.blocks.parse(content || '');
			if (!parsedBlocks || !parsedBlocks.length) {
				return false;
			}

			var dispatch = wpInstance.data.dispatch('core/block-editor');
			var selector = wpInstance.data.select('core/block-editor');

			if (!dispatch || !dispatch.insertBlocks) {
				return false;
			}

			// Resolve the anchor: prefer the explicit clientId passed in
			// (typically from the contextual block-settings menu / List
			// View invocation); fall back to whatever block is currently
			// selected in the canvas.
			var anchorId = anchorClientId || null;
			if (!anchorId && selector && selector.getSelectedBlock) {
				var selected = selector.getSelectedBlock();
				if (selected) {
					anchorId = selected.clientId;
				}
			}

			var rootId = anchorId && selector.getBlockRootClientId ? selector.getBlockRootClientId(anchorId) : undefined;
			// WordPress 6.3+ deprecated the second `rootClientId` argument to
			// getBlockIndex — pass only the clientId. The block's index within
			// its parent is already deterministic from the clientId alone.
			// rootId is still passed to insertBlocks() below so the new block
			// lands inside the correct parent container.
			var index = anchorId && selector.getBlockIndex
				? (function () {
					try { return selector.getBlockIndex(anchorId) + 1; }
					catch (e) { return undefined; }
				})()
				: undefined;

			dispatch.insertBlocks(parsedBlocks, index, rootId);
			return true;
		} catch (e) {
			console.error('[WDesignKit] insertBlocks failed:', e);
			return false;
		}
	}

	function gutenbergPaste(text, anchorClientId) {
		if (!window.wp || !wp.blocks || !wp.data) {
			notify(__('No Gutenberg content found on the clipboard.', 'wdesignkit'), 'warning');
			return false;
		}

		// Prefer raw block markup (demos site / new copy format); fall back
		// to the legacy WDesignKit wrapper for clipboards from older copies.
		var content = parseDemoGutenberg(text);

		if (!content) {
			var wrapped = parsePayload(text);
			if (wrapped && 'gutenberg' === wrapped.builder && wrapped.content) {
				content = wrapped.content;
			}
		}

		if (!content) {
			notify(__('No Gutenberg content found on the clipboard.', 'wdesignkit'), 'warning');
			return false;
		}

		// Guard: detect block types from other plugins that this site can't
		// render (parsed as `core/missing`). Pasting them would drop a broken
		// "missing block" placeholder instead of the real content, so stop
		// and tell the user which plugin to install.
		var foreignMissing = gutenbergForeignMissingTypes(content, window.wp);
		if (foreignMissing.length) {
			showMissingPluginPopup(foreignMissing, 'gutenberg');
			return false;
		}

		// Try parent-frame wp first — the core/block-editor store always lives
		// here, even in WP 6.3+ where the canvas is inside an iframe.
		var inserted = gutenbergInsertBlocksVia(window.wp, content, anchorClientId);

		// Fallback: editor-canvas iframe's wp (WP 6.3+ iframed editor).
		if (!inserted) {
			inserted = gutenbergInsertBlocksVia(gutenbergGetWp(), content, anchorClientId);
		}

		if (inserted) {
			notify(__('Content pasted successfully across domains.', 'wdesignkit'), 'success');
			return true;
		}

		notify(__('Could not paste — please click in the editor canvas first.', 'wdesignkit'), 'warning');
		return false;
	}

	function gutenbergPasteFromClipboard(clientIds) {
		// Snapshot the first contextual clientId — used as the paste
		// anchor so the new blocks land after the block whose menu was
		// invoked (matters when the menu came from List View, where the
		// "selected" block in the canvas is something else).
		var anchorClientId = Array.isArray(clientIds) && clientIds.length ? clientIds[0] : null;

		// readClipboard() returns the live clipboard text (and falls back to
		// localStorage). gutenbergPaste() itself recognises both the raw
		// block markup (new + demos-site format) and the legacy WDesignKit
		// wrapper, so just hand the text straight to it.
		return readClipboard().then(function (text) {
			if (!text) {
				notify(__('No Gutenberg content found on the clipboard.', 'wdesignkit'), 'warning');
				return false;
			}

			// Resolve the raw block markup (same logic as gutenbergPaste).
			var markup = parseDemoGutenberg(text);
			if (!markup) {
				var wrapped = parsePayload(text);
				if (wrapped && 'gutenberg' === wrapped.builder && wrapped.content) {
					markup = wrapped.content;
				}
			}
			if (!markup) {
				notify(__('No Gutenberg content found on the clipboard.', 'wdesignkit'), 'warning');
				return false;
			}

			// Pre-step: detect any WDesignKit custom widgets (wb-*)
			// referenced by the pasted block markup, then download them
			// (with live popup progress) before pasting. Same UX as
			// Elementor — a failed download names the widget and shows why.
			return checkRequiredWidgets(markup, 'gutenberg').then(function (check) {
				if (check.ok) {
					return gutenbergPaste(text, anchorClientId);
				}
				return handleMissingWidgets(check.missing, function () {
					return gutenbergPaste(text, anchorClientId);
				}, 'gutenberg');
			});
		});
	}

	function gutenbergInstallDocListeners(doc) {
		if (!doc || doc.__wdkitGbHooked) {
			return;
		}
		doc.__wdkitGbHooked = true;

		// Auto-detect WDesignKit payload on native paste.
		doc.addEventListener('paste', function (event) {
			var raw = event.clipboardData ? event.clipboardData.getData('text/plain') : '';
			if (!parsePayload(raw)) {
				return;
			}
			if (gutenbergPaste(raw)) {
				event.preventDefault();
				event.stopPropagation();
			}
		}, true);

		// Mirror Ctrl+Shift+C / Ctrl+Shift+V shortcut into the editor canvas
		// iframe (WP 6.3+) — otherwise keypresses inside the iframe never
		// reach the parent listener.
		doc.addEventListener('keydown', sharedShortcutHandler, true);
	}

	function findGutenbergEditorIframe() {
		return document.querySelector('iframe[name="editor-canvas"]')
			|| document.querySelector('.editor-styles-wrapper iframe')
			|| document.querySelector('.edit-post-visual-editor iframe')
			|| document.querySelector('.block-editor__container iframe')
			|| document.querySelector('.interface-interface-skeleton__content iframe')
			|| null;
	}

	function gutenbergRegisterBlockMenu() {
		if (gutenbergRegisterBlockMenu.done) {
			return;
		}
		if (!window.wp || !wp.plugins || !wp.element || !wp.blockEditor || !wp.components) {
			return;
		}
		var registerPlugin = wp.plugins.registerPlugin;
		var createElement = wp.element.createElement;
		var Fragment = wp.element.Fragment;
		var MenuControls = wp.blockEditor.BlockSettingsMenuControls;
		var MenuItem = wp.components.MenuItem;

		if (!registerPlugin || !createElement || !MenuControls || !MenuItem) {
			return;
		}

		gutenbergRegisterBlockMenu.done = true;

		var Slot = function () {
			return createElement(MenuControls, null, function (slotProps) {
				var onClose = (slotProps && slotProps.onClose) || function () { };

				// Gutenberg passes the contextual block IDs to the slot
				// render — `selectedClientIds` in modern WP, `clientIds`
				// in older variants. We capture them so the action
				// targets the block whose menu was opened, even when the
				// invocation came from the List View (where the block is
				// not the canvas-selected one).
				var menuClientIds = (slotProps && (slotProps.selectedClientIds || slotProps.clientIds)) || [];

				return createElement(
					Fragment || 'div',
					null,
					createElement(MenuItem, {
						icon: 'admin-page',
						onClick: function () {
							// Snapshot the ids BEFORE onClose() runs —
							// closing the menu can trigger a re-render
							// that clears slotProps.
							var ids = menuClientIds.slice();
							onClose();
							// Defer past the menu-close render so wp.data
							// dispatches run on a settled store.
							setTimeout(function () { gutenbergCopy(ids); }, 80);
						},
					}, __('WDesignKit Copy', 'wdesignkit')),
					createElement(MenuItem, {
						icon: 'clipboard',
						onClick: function () {
							var ids = menuClientIds.slice();
							onClose();
							setTimeout(function () { gutenbergPasteFromClipboard(ids); }, 80);
						},
					}, __('WDesignKit Paste', 'wdesignkit'))
				);
			});
		};

		try {
			registerPlugin('wdesignkit-cross-copy-paste', { render: Slot });
		} catch (e) {
			// Already registered or another error — safe to ignore.
		}
	}

	function initGutenberg() {
		if (!builders.gutenberg || !window.wp || !wp.data || !wp.blocks || initGutenberg.loaded) {
			return;
		}

		initGutenberg.loaded = true;

		gutenbergInstallDocListeners(document);

		// WordPress-approved menu: registered via SlotFill into the block
		// toolbar's "Options" (three-dot) menu.
		var tryRegister = function () { gutenbergRegisterBlockMenu(); };
		if (window.wp && wp.domReady) {
			wp.domReady(tryRegister);
		}
		tryRegister();
		setTimeout(tryRegister, 800);
		setTimeout(tryRegister, 2500);

		// WP 6.3+ moves the editor canvas into an iframe. Hook it (re-hook on reload).
		var hookEditorIframe = function () {
			var iframe = findGutenbergEditorIframe();
			if (!iframe) {
				return;
			}
			try {
				if (iframe.contentDocument) {
					gutenbergInstallDocListeners(iframe.contentDocument);
				}
			} catch (e) { }
			if (!iframe.__wdkitGbWatched) {
				iframe.__wdkitGbWatched = true;
				iframe.addEventListener('load', function () {
					try {
						if (iframe.contentDocument) {
							gutenbergInstallDocListeners(iframe.contentDocument);
						}
					} catch (e) { }
				});
			}
		};

		hookEditorIframe();
		setTimeout(hookEditorIframe, 800);
		setTimeout(hookEditorIframe, 2500);
		setTimeout(hookEditorIframe, 6000);

		// Iframe may appear later (slow editor init) — watch the DOM for it.
		if (window.MutationObserver && !initGutenberg.observer) {
			initGutenberg.observer = new MutationObserver(function () {
				hookEditorIframe();
				tryRegister();
			});
			try {
				initGutenberg.observer.observe(document.body, { childList: true, subtree: true });
			} catch (e) { }
		}
	}

	// ---------------------------------------------------------------
	// Bricks (clipboard-piggyback strategy)
	//
	// Bricks 1.5+ uses navigator.clipboard for its native Ctrl+C / Ctrl+V.
	// Instead of fighting Bricks' Vue/Pinia internals (different every
	// version), we transparently:
	//   - wrap whatever Bricks copies with a cross-domain payload
	//   - unwrap before Bricks' paste handler reads it
	//
	// Result: the user just uses native Ctrl+C / Ctrl+V; cross-domain
	// transfer "just works" because the wire format is Bricks' own JSON,
	// only re-tagged so other sites can recognise it as ours.
	//
	// KEY FIX: Bricks runs in an iframe. navigator.clipboard.readText()
	// throws "Document is not focused" from within an iframe context.
	// We solve this by using localStorage as the PRIMARY read source in
	// all Bricks paste paths — writeClipboard() always writes there, so
	// the data is always available regardless of focus state.
	// ---------------------------------------------------------------

	function bricksIsBuilder() {
		var href = window.location ? (window.location.href || '') : '';
		var body = document.body ? document.body.className : '';
		if (/bricks=run|brickspreview|bricks-is-builder|brx-iframe/i.test(href + ' ' + body)) {
			return true;
		}
		if (window.bricksData) {
			return true;
		}
		try {
			if (window.parent && window.parent !== window && window.parent.bricksData) {
				return true;
			}
		} catch (e) { }
		return false;
	}

	function bricksGetIframe() {
		return document.querySelector('iframe#bricks-builder-iframe')
			|| document.querySelector('iframe[name="bricks-preview-iframe"]')
			|| document.querySelector('iframe.brx-iframe')
			|| document.querySelector('.brx-builder iframe')
			|| document.querySelector('iframe');
	}

	function bricksGetCanvasDoc() {
		var iframe = bricksGetIframe();
		if (!iframe) {
			return null;
		}
		try {
			return iframe.contentDocument || (iframe.contentWindow && iframe.contentWindow.document) || null;
		} catch (e) {
			return null;
		}
	}

	function looksLikeBricksClipboard(text) {
		if (!text || 'string' !== typeof text) {
			return false;
		}
		var trimmed = text.trim();
		if ('{' !== trimmed.charAt(0) && '[' !== trimmed.charAt(0)) {
			return false;
		}
		try {
			var parsed = JSON.parse(trimmed);
			if (!parsed || 'object' !== typeof parsed) {
				return false;
			}
			// Bricks copy formats we've seen across versions:
			//   { source: "bricksCopiedElements", elements: [...], ... }
			//   { content: [...], type: "..." }
			//   [ { id, name, settings, parent, children, ... } ]
			if (Array.isArray(parsed)) {
				return parsed.length && parsed[0] && (parsed[0].name || parsed[0].settings || parsed[0].id);
			}
			if (parsed.source && /^bricks/i.test(parsed.source)) {
				return true;
			}
			if (parsed.elements || parsed.content) {
				return true;
			}
			if (parsed.name && parsed.settings) {
				return true;
			}
			return false;
		} catch (e) {
			return false;
		}
	}

	function onBricksCopy(event) {
		// STRATEGY 1 — synchronous bubble-phase interception of clipboardData.
		// If Bricks populated event.clipboardData via setData (works for most
		// versions), we wrap it in-place with full user-activation context.
		if (event && event.clipboardData) {
			try {
				var native = event.clipboardData.getData('text/plain');
				if (native && !parsePayload(native) && looksLikeBricksClipboard(native)) {
					var wrapped = payload('bricks', native);
					event.clipboardData.setData('text/plain', wrapped);
					event.preventDefault();
					try { window.localStorage.setItem(STORAGE_KEY, wrapped); } catch (e) { }
					notify(__('Bricks element copied for cross-domain paste.', 'wdesignkit'), 'success');
					return;
				}
			} catch (e) { }
		}

		// STRATEGY 2 — Bricks may use navigator.clipboard.writeText (async).
		// Re-read clipboard after a tick and wrap.
		setTimeout(function () {
			if (!navigator.clipboard || !navigator.clipboard.readText) {
				return;
			}
			navigator.clipboard.readText().then(function (text) {
				if (!text || parsePayload(text)) {
					return;
				}
				if (!looksLikeBricksClipboard(text)) {
					return;
				}
				var wrapped = payload('bricks', text);
				writeClipboard(wrapped).then(function () {
					notify(__('Bricks element copied for cross-domain paste.', 'wdesignkit'), 'success');
				});
			}).catch(function () { });
		}, 700);
	}

	// ---------------------------------------------------------------
	// Bricks clipboard.readText patch
	//
	// Bricks' paste handler reads clipboard via navigator.clipboard.readText().
	// We temporarily override readText on both the parent frame and the
	// canvas iframe so it returns our native Bricks JSON, bypassing the OS
	// clipboard entirely.  Returns a restore() callback.
	// ---------------------------------------------------------------
	function bricksPatchClipboardRead(nativeText) {
		var patched = [];

		function patchOne(clipboardObj, label) {
			if (!clipboardObj || typeof clipboardObj.readText !== 'function') return;
			try {
				var orig = clipboardObj.readText.bind(clipboardObj);
				var calls = 0;
				clipboardObj.readText = function () {
					if (++calls <= 5) return Promise.resolve(nativeText);
					clipboardObj.readText = orig;
					return orig();
				};
				patched.push({ obj: clipboardObj, orig: orig });
			} catch (e) {
				console.warn('[WDesignKit] patch failed on', label, e);
			}
		}

		// Patch parent
		try { patchOne(navigator.clipboard, 'parent'); } catch (e) { }

		// Patch iframe — try multiple selector strategies
		var iframeSelectors = [
			'iframe#bricks-builder-iframe',
			'iframe[name="bricks-preview-iframe"]',
			'iframe.brx-iframe',
			'.brx-builder iframe',
			'iframe'
		];

		for (var i = 0; i < iframeSelectors.length; i++) {
			try {
				var el = document.querySelector(iframeSelectors[i]);
				if (el && el.contentWindow && el.contentWindow.navigator && el.contentWindow.navigator.clipboard) {
					patchOne(el.contentWindow.navigator.clipboard, 'iframe:' + iframeSelectors[i]);
					break; // patch the first one found
				}
			} catch (e) { }
		}

		return function restore() {
			patched.forEach(function (p) {
				try { p.obj.readText = p.orig; } catch (e) { }
			});
		};
	}

	function bricksPaste() {
		return readClipboardBricks().then(function (text) {
			var nativeText = null;
			var data = parsePayload(text);

			if (data && 'bricks' === data.builder) {
				nativeText = 'string' === typeof data.content ? data.content : JSON.stringify(data.content);
			} else if (looksLikeBricksClipboard(text)) {
				nativeText = text;
			} else {
				notify(__('Copy a Bricks element first, then paste.', 'wdesignkit'), 'warning');
				return false;
			}

			// 1. Patch readText FIRST — before any events fire
			var restore = bricksPatchClipboardRead(nativeText);

			// 2. Also update localStorage so readClipboardBricks() fallback has clean data
			try { window.localStorage.setItem(STORAGE_KEY, nativeText); } catch (e) { }

			var doc = bricksGetCanvasDoc() || document;

			// 4. Focus canvas so Bricks listeners are active
			try {
				var iframe = bricksGetIframe();
				if (iframe && iframe.contentWindow) iframe.contentWindow.focus();
				if (doc && doc.body) doc.body.focus();
			} catch (e) { }

			// 5. Small delay — let focus settle, then fire paste
			setTimeout(function () {
				// Re-patch after focus shift (focus change can reset some envs)
				var restore2 = bricksPatchClipboardRead(nativeText);

				// Dispatch a native ClipboardEvent paste — far more reliable than a
				// synthetic keyboard event because Bricks' Vue layer (and Chrome/Safari)
				// will honour it even inside an iframe, unlike a trusted-flag-only Ctrl+V.
				try {
					var pasteEvent = new ClipboardEvent('paste', {
						bubbles: true,
						cancelable: true,
						clipboardData: new DataTransfer(),
					});
					pasteEvent.clipboardData.setData('text/plain', nativeText);
					(doc.activeElement || doc.body || doc).dispatchEvent(pasteEvent);
				} catch (e) {
					console.error('[WDesignKit] Bricks paste dispatch failed:', e);
					// Absolute last resort — fire synthetic Ctrl+V
					bricksDispatchKey(doc, 'v', 'KeyV', 86);
				}

				setTimeout(function () {
					restore();
					restore2();
					// Restore WDesignKit wrapper so cross-domain chain still works
					writeClipboard(payload('bricks', nativeText));
				}, 1200);
			}, 80);

			notify(__('Bricks element pasted.', 'wdesignkit'), 'success');
			return true;
		});
	}

	function onBricksPaste(event) {
		var text = event.clipboardData ? event.clipboardData.getData('text/plain') : '';
		var data = parsePayload(text);

		if (!data || 'bricks' !== data.builder) {
			return; // not ours — let Bricks (or the browser) handle natively
		}

		var nativeText = 'string' === typeof data.content ? data.content : JSON.stringify(data.content);

		// Patch readText so that when Bricks' own paste handler calls
		// navigator.clipboard.readText() it gets native JSON, not our wrapper.
		var restore = bricksPatchClipboardRead(nativeText);
		setTimeout(restore, 1000);

		// Best-effort write to system clipboard (async, may or may not arrive
		// before Bricks reads it — the patch above is the reliable path).
		writeClipboard(nativeText);
		notify(__('Cross-domain content ready — pasting…', 'wdesignkit'), 'success');
		// Do NOT preventDefault: let Bricks' own paste logic run.
	}

	function bricksDispatchKey(doc, key, code, keyCode) {
		try {
			var win = (doc && doc.defaultView) || window;
			var Ctor = win.KeyboardEvent || KeyboardEvent;
			var evt = new Ctor('keydown', {
				key: key, code: code, keyCode: keyCode, which: keyCode,
				ctrlKey: true, bubbles: true, cancelable: true,
			});
			(doc.body || doc).dispatchEvent(evt);
			return true;
		} catch (e) {
			return false;
		}
	}

	function bricksCopy() {
		// Trigger native Bricks Ctrl+C; the copy hook will wrap the result.
		var doc = bricksGetCanvasDoc() || document;
		var fired = bricksDispatchKey(doc, 'c', 'KeyC', 67);
		if (!fired) {
			notify(__('Bricks canvas not reachable.', 'wdesignkit'), 'warning');
			return Promise.resolve(false);
		}
		// Bricks will write to clipboard; our copy hook wraps it.
		return Promise.resolve(true);
	}

	function bricksInstallListeners(doc) {
		if (!doc || doc.__wdkitBricksHooked) {
			return;
		}
		doc.__wdkitBricksHooked = true;
		// Bubble phase for copy so Bricks' own handler (which fills
		// event.clipboardData) has already run when ours fires.
		doc.addEventListener('copy', onBricksCopy, false);
		doc.addEventListener('paste', onBricksPaste, true);
		// Mirror Ctrl+Shift+C / Ctrl+Shift+V shortcut into the iframe canvas.
		doc.addEventListener('keydown', sharedShortcutHandler, true);
	}

	// ---------------------------------------------------------------
	// Bricks context-menu injection
	//
	// Bricks renders its right-click context menu as
	//   #bricks-builder-context-menu > ul > li
	// in the MAIN (non-iframe) builder document.
	// Each item uses <span class="label"> and <span class="shortcut">.
	//
	// We use a MutationObserver to detect when Vue re-renders the list
	// (every right-click) and append "WDesignKit Copy / Paste" items.
	// ---------------------------------------------------------------

	function bricksInjectContextItems(ul) {
		// Avoid injecting twice into the same render cycle.
		if (ul.querySelector('.wdkit-ctx-copy')) {
			return;
		}

		var sep = document.createElement('li');
		sep.className = 'sep';

		var copyLi = document.createElement('li');
		copyLi.className = 'wdkit-ctx-copy';
		copyLi.innerHTML =
			'<span class="label">' + __('WDesignKit Copy', 'wdesignkit') + '</span>' +
			'<span class="shortcut">Ctrl+Shift+C</span>';
		copyLi.addEventListener('click', function () {
			// Small delay: Vue's onClickCapture closes the menu first,
			// then we fire the copy so the canvas element is still active.
			setTimeout(function () { bricksCopy(); }, 80);
		});

		var pasteLi = document.createElement('li');
		pasteLi.className = 'wdkit-ctx-paste';
		pasteLi.innerHTML =
			'<span class="label">' + __('WDesignKit Paste', 'wdesignkit') + '</span>' +
			'<span class="shortcut">Ctrl+Shift+V</span>';
		pasteLi.addEventListener('click', function () {
			setTimeout(function () { bricksPaste(); }, 80);
		});

		ul.appendChild(sep);
		ul.appendChild(copyLi);
		ul.appendChild(pasteLi);
	}

	function initBricksContextMenu() {
		if (initBricksContextMenu.loaded || !window.MutationObserver) {
			return;
		}
		initBricksContextMenu.loaded = true;

		// Watch the main document body for #bricks-builder-context-menu to
		// receive child <li> elements (Vue re-renders on each right-click).
		var observer = new MutationObserver(function () {
			var menu = document.getElementById('bricks-builder-context-menu');
			if (!menu) {
				return;
			}
			var ul = menu.querySelector('ul');
			// Only act when there are native Bricks items already in the list.
			if (!ul || !ul.querySelector('li:not(.wdkit-ctx-copy):not(.wdkit-ctx-paste):not(.sep)')) {
				return;
			}
			bricksInjectContextItems(ul);
		});

		observer.observe(document.body, { childList: true, subtree: true });
	}

	function initBricks() {
		if (!builders.bricks || !bricksIsBuilder()) {
			return;
		}

		// Install clipboard hooks on the main builder document.
		bricksInstallListeners(document);

		// Install on the canvas iframe as well — that's where most Bricks
		// keyboard interactions actually happen.
		var hookIframe = function () {
			var iframe = bricksGetIframe();
			if (iframe && !iframe.__wdkitBricksWatched) {
				iframe.__wdkitBricksWatched = true;
				iframe.addEventListener('load', function () {
					try {
						var doc = iframe.contentDocument;
						if (doc) bricksInstallListeners(doc);
					} catch (e) { }
				});
			}
			var doc = bricksGetCanvasDoc();
			if (doc) {
				bricksInstallListeners(doc);
			}
		};

		hookIframe();
		setTimeout(hookIframe, 800);
		setTimeout(hookIframe, 2500);
		setTimeout(hookIframe, 6000);

		// Inject "WDesignKit Copy / Paste" into Bricks' right-click context menu.
		initBricksContextMenu();

		initBricks.loaded = true;
	}

	// ---------------------------------------------------------------
	// Shared keyboard shortcut: Ctrl+Shift+C / Ctrl+Shift+V
	// (Native Ctrl+C / Ctrl+V is left untouched.)
	// ---------------------------------------------------------------

	function activeBuilder() {
		if (builders.elementor && window.elementor) {
			return 'elementor';
		}
		if (builders.bricks && bricksIsBuilder()) {
			return 'bricks';
		}
		if (builders.gutenberg && window.wp && wp.blocks && wp.data && wp.data.select && wp.data.select('core/block-editor')) {
			return 'gutenberg';
		}
		return '';
	}

	// Keyboard shortcuts (Ctrl+Shift+C / Ctrl+Shift+V) are intentionally
	// disabled — cross-domain copy/paste is only available via the
	// "WDesignKit Copy" / "WDesignKit Paste" menu entries for now.
	// The handler is kept as a no-op so existing listener installs in
	// the Gutenberg / Bricks iframes don't error out.
	function sharedShortcutHandler() {
		return;
	}

	function bindShortcuts() {
		// no-op — see sharedShortcutHandler note above.
	}

	// ---------------------------------------------------------------
	// Boot
	// ---------------------------------------------------------------

	function boot() {
		try { initElementor(); } catch (e) { }
		try { initGutenberg(); } catch (e) { }
		try { initBricks(); } catch (e) { }
		try { bindShortcuts(); } catch (e) { }
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}

	window.addEventListener('load', boot);

	// Editors initialise asynchronously — retry a few times.
	setTimeout(boot, 1500);
	setTimeout(boot, 4000);
	setTimeout(boot, 8000);

})();