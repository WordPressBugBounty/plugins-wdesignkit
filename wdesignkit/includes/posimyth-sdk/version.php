<?php
/**
 * Version of THIS bundled copy of the POSIMYTH Analytics SDK.
 *
 * Read by posimyth-sdk-loader.php to decide which plugin's copy of the shared classes wins when
 * several POSIMYTH plugins are active. Bump it on ANY change to the shared SDK files, or an
 * updated plugin's fixes can silently lose to a sibling's older copy.
 *
 * 2.22.0 — the transport no longer blocks the browser on hosts that cannot flush the response.
 * do_request() set 'blocking' => false where fastcgi_finish_request() is unavailable (Apache mod_php,
 * LiteSpeed's PHP handler, php-cgi) on the belief that WordPress would write the request and not wait
 * for the reply. It does wait: WP_Http_Curl::request() still calls curl_exec() synchronously when
 * blocking is false and merely skips the write callbacks, so the worker AND the browser paid the full
 * hub round trip — ~4s, capped by the 5s timeout — on activate, deactivate and every catch-up
 * heartbeat. Measured: a dashboard load 0.13s → 5.96s, a deactivate ~1s → 5.27s. Now
 * litespeed_finish_request() is used where it exists, cron and WP-CLI requests block properly because
 * nothing is waiting on them, and on a user-facing request that cannot flush the event is handed to a
 * one-off cron event instead — which also moves the heartbeat's content scan off the admin request.
 * `deactivate` and the survey's own submission cannot be queued (the plugin is about to be inactive,
 * so nothing would be listening) and send inline under a hard 2s ceiling. The WP_DEBUG diagnostic
 * added in 2.21.0 follows the same condition, so it now works on mod_php instead of being disabled on
 * exactly the hosts that needed it. Also: enabled_widgets, widget_usage and plugin_meta are cast to
 * objects, so an empty map no longer serialises as `[]` while a populated one serialises as `{}` —
 * the hub joins the first two against each other and was receiving two JSON types for one key.
 *
 * 2.21.0 — a failed ping is no longer silent. do_request() discarded the hub's response entirely, so
 * a hub that accepted the request and then failed to store it was invisible to every product at once:
 * pings kept going out, rows stopped arriving, and nothing anywhere said so. It was found by probing
 * the endpoint by hand, which is not a monitoring strategy. Under WP_DEBUG, and only for the blocking
 * path where a status actually exists, a non-2xx reply now writes one line to the debug log. Nothing
 * changes for a site with debugging off.
 *
 * 2.20.0 — the reconciliation the previous revisions kept asking for. All four plugins now ship the
 * SAME three shared files, byte for byte apart from their text domain, and the SAME version number.
 * That restores the loader's own stated assumption ("the copies are byte-identical apart from their
 * text domain, so a tie has no wrong answer") and, with it, the point of this file: it now records
 * which BUILD a copy is, and no longer decides whose design or whose feature set every sibling gets.
 *
 * What was reconciled, and what each divergence had been costing:
 *
 *  - Consent notice: the 2.19.0 treatment is now everyone's. The accent arrives as an inline
 *    `--posi-accent` custom property on each instance's own markup and the stylesheet keys only on
 *    the stable `posi-*` classes, so one stylesheet serves N brand colours. Before this, three copies
 *    baked a literal colour and a product-specific class prefix into a stylesheet that is printed
 *    ONCE for the whole page — which is how The Plus Addons' purple painted Sticky Header Effects'
 *    notice, and Sticky Header Effects' raspberry painted The Plus Addons'. Never reintroduce a
 *    prefix-keyed selector or a literal colour here: the stylesheet's instance and the markup's
 *    instance are latched separately, so they are not guaranteed to be the same product.
 *
 *  - Deactivation survey: the 2.16.0 design (accent top border, backdrop blur, 575px dialog) plus
 *    callable `reasons`. The callable support existed only in the Nexter copies while a copy without
 *    it held the highest version, and a copy that only accepts arrays does not error on a closure —
 *    is_array() is simply false, so the config collapsed to the built-in seven. Nexter Extension and
 *    Nexter Blocks both pass a closure, so both silently shipped the generic reason list instead of
 *    their own branded cards. Callables are the supported way to defer __() out of `plugins_loaded`,
 *    so dropping that block also re-breaks WP 6.7+ translation timing.
 *
 * Every product passes its own `accent` (and the legacy `css_prefix`) explicitly, even where the
 * value equals this SDK's neutral default. Branding is never inherited: a product that passes nothing
 * is painted by whichever copy won the loader, and that is precisely the coupling this revision
 * removes. The defaults here stay product-neutral for the same reason.
 *
 * Keeping it this way: change the shared files in ONE place, re-sync all five, bump this number in
 * all five together. A copy that diverges again re-creates the exact failure mode above, and it fails
 * silently — nothing errors, a sibling's design or defaults simply take over.
 *
 * @package POSIMYTH\Analytics\SDK
 */

return '2.22.0';
