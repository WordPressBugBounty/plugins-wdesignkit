---
name: wdesignkit-abilities
description: >
  Complete guide to building with the WDesignKit MCP abilities (wdesignkit/,
  nexter-blocks/, nexter/) — create, edit, duplicate, push, and import
  widgets, templates, kits, and code snippets, and manage workspaces and
  licences, correctly on the first try. Invoke this BEFORE calling any
  wdesignkit/ or nexter-blocks/* ability. Grounded in the full live ability
  catalog and verified tool specs.
---

# WDesignKit Abilities Guide

The user wants a working widget/template/site — deliver that outcome. Work quietly; only surface things that change what the user should expect (which builder, which page, whether something gets replaced). Never narrate ability internals.

Unsure of an ability's fields? Pull its spec (`sprout-gateway-tool-spec`) before a one-shot or irreversible call. Call `wdesignkit/list-abilities` whenever an ability isn't covered here, its name is unclear, or the builder/action is ambiguous — it enumerates the live catalog so you never guess a name.

## 🔴 NEXTER BLOCKS — DEDICATED ABILITIES ONLY

Nexter blocks (`tpgb/*`) render from their saved block HTML, so every element must be created through its own `nexter-blocks/add-tpgb-*` ability — generic page tools (`assemble-page`, `patch-tree`, `html-to-builder`, `write-builder-content`, `place-*`) store attributes only, which is not enough for a Nexter block to display.

1. Add each element via `nexter-blocks/add-tpgb-*` (container, heading, button-core, post-content, post-image, post-title, switcher, tabs-tours, ...).
2. Nest by passing the container's returned `block_id` as the child's `parent_block_id` — parent first, children second.
3. Always finish with `nexter-blocks/verify-page`; rebuild any listed blocks with the matching `add-tpgb-*` ability.

Applies to edits too — route even a small tweak through `add-tpgb-*`, then `verify-page`.

## CORE RULES (apply to everything below)

- **Read before write.** Resolve names → ids/folders via the matching list/find/get ability first; never guess.
- **Login before cloud.** Push, save-template, workspaces, licences, pro downloads need cloud login — `get-login-status` when unsure.
- **`dry_run: true` before `confirm: true`** on any irreversible ability (`delete-widget`, `delete-workspace`, `delete-licence`, `delete-snippet`, `remove-template`, `replace-template`, `remove-database`, `reset-white-label`, `rollback`) whose target was matched by name rather than a locked-in id.
- **Search before creating** — avoid near-duplicates. Widget names are unique per builder (letters, numbers, spaces, hyphens, underscores; max 64 chars).
- **Verify with the real mechanism** (`widget-preview`, `nexter-blocks/verify-page`, a live render) before reporting done.
- **Confirm with the user before:** switching the active theme, rollback, `remove-database execute`, `delete-template-builder`, entering credentials or a licence key, or acting across an ambiguous workspace.
- **Clean up** scratch pages/imports created for your own verification.
- **A declined call is usually a safeguard** (duplicate widget_id, unknown category) — resolve the underlying question, don't route around it. Retry only transient failures, a bounded number of times.

## DISCOVERY & PREREQUISITES

- **`wdesignkit/categories`** (list/manage) — every widget and template save needs an existing category; `list-categories` first, `manage-categories` to add one before create-widget/save-template are called.
- **`check-dependencies` / `install-dependency`** — gates widgets, pro presets, and pro code snippets alike. Run it before any create/import/download that targets a pro builder or pro asset, not just widgets.
- **`wdesignkit/list-abilities`** — the live catalog; use it to resolve an unfamiliar or ambiguous ability name instead of guessing.

## AUTH

Cloud abilities (push, save-template, workspaces, licences, pro downloads) all require a session.

- `get-login-status` — check first; returns state, email, token expiry, credits.
- `login` (`remember_me: true` = 90-day session), `login-api-key`, `signup`, `forgot-password`, and the social flow (`get-social-login-url` → user authorises in popup → `social-login` with state).
- **Credentials and licence keys always need the user's confirmation in chat first** — never enter them silently.

## WIDGETS

### Builder mapping

| User says | builder= |
|---|---|
| "Elementor" | `elementor` |
| "Gutenberg", "block editor", "core blocks" | `gutenberg_core` |
| "Nexter", "TPGB", "Nexter Blocks" | `gutenberg` — Nexter rule above governs all page work |
| "Bricks" | `bricks` — needs the Bricks theme active to display (see Themes) |

Never guess the builder — ask if ambiguous. `convert-widget` returns the recommended manual workflow for moving between builders (each builder has its own architecture); set that expectation and follow it.

### Create → code → verify

1. `list-widgets` + `check-dependencies` (install-dependency if needed)
2. `create-widget(name, builder, description, icon, keywords)` — let it generate boilerplate; omit `php_code` here
3. `get-widget` — read the real class name, hash, paths
4. `validate-widget(php_code)` — validate PHP syntax, namespace rules, class naming, get_name(), and asset dependencies before writing
5. `update-widget` with your real code (pass `validate: true` for inline pre-write validation)
6. `sync-widget-code(builder, folder, direction="code_to_section")` — resync `section_data` from `php_code` to prevent control drift
7. `widget-preview` to verify (Elementor / Gutenberg / gutenberg_core; other builders: activation status + live render)

**create-widget contract:** `category` must already exist in `list-categories` (`manage-categories` to add). Returned `widget_id` = 8-char hash, never changes; use it with activate/deactivate, and `folder` with get/update. `cdn_js[]` / `cdn_css[]` for third-party libraries.

**PHP rules (when providing php_code):** one class per file, global namespace with `use Elementor\...` imports; class name `Wdkit_{name_snake}_{hash}`; `get_name()` returns `wb-{hash}` (unique across widgets); `get_script_depends()` + `get_style_depends()` required — they load the widget's CSS/JS on the frontend. Bricks extends `\Bricks\Element`; Gutenberg calls `register_block_type()` on `init`. `section_data` is auto-parsed from `register_controls()` — only pass it to override. Run `validate-widget` before updating PHP and `sync-widget-code` after raw `update-widget` code edits.

**JS rule (Elementor):** runs in `element_ready` scope — use `$scope[0].querySelector('...')`, no jQuery wrapper. Omit `js_code` when no JS is needed.

**update-widget contract:** each provided code field replaces the ENTIRE file — send complete content from a fresh `get-widget` read. Omitted fields stay untouched. `name` also updates `get_title()` / `get_label()`; `version` also updates asset enqueue strings. Folder + widget_id never change.

### Duplicate

`duplicate-widget(builder, folder, new_name)` — fresh `widget_id`, cloud records stay with the original (push separately if the user wants the copy in their cloud library). If the copy has real code, immediately: rename the class to its own id, make `get_name()` / `$name` /block-name unique, repoint hardcoded asset paths. Skip for boilerplate-only copies.

### Activate / deactivate / delete

Activation abilities only flip load status — files untouched. Use `deactivate-widget` to "hide this for now" (`activate-widget` to bring it back); `delete-widget` (`confirm: true`, dry_run supported) moves files to a recoverable trash.

### Cloud: push / download / transfer

- `set-widget-thumbnail(builder, folder, image_id|image_url|image_base64)` — attach or replace the preview thumbnail for a local widget so `push-widget` can upload it.
- `push-widget(builder, folder, type)` — `"new"` first push, `"update"` once an `r_id` exists (push writes `r_id` back, which powers `check-widget-versions`). Thumbnail required before pushing.
- `pull-widget(r_id, builder, folder, confirm)` — re-download your own cloud widget copy over the local widget (inverse of push-widget). Guarded with `confirm: true` and `dry_run: true`.
- `check-widget-versions` — compares the local `r_id` against the marketplace record; run it before `push "update"` to confirm there actually is a newer cloud version, and after push to confirm the sync landed.
- `export-widget` → `.zip` → `import-widget(widget_data)` on another site. Import declines a duplicate `widget_id` — ask: new duplicate or update the existing one?
- `download-widget` (marketplace `w_uniq`) + `browse-widgets` for the public marketplace. Match the path to what the user has: a listing vs. an exported file.

## TEMPLATES

`find-template` / `list-templates` return ids accepted directly by update / replace / remove / import — resolve names through them first.

- `save-template(builder, data, post_id, name, type)` — builder is `elementor` | `gutenberg`; `data` is plain JSON / serialized markup, NOT base64. `post_id` captures `nxt-*` meta. `name` defaults to the post title — confirm it landed after saving. Check `find-template` for a near-duplicate first.
- **update-template** — in-place iteration (optional `post_id` refreshes meta). **replace-template** — wholesale swap with the overwrite safeguard (`confirm` + `dry_run`). **remove-template** — `confirm` + `dry_run`.
- **import-template(template_id, ...)** returns content — insertion is your job. `editor`, `with_dummy_data`, `custom_meta` (editor context only), `website_kit` / `api_type` for kits, `site_title` / `site_type` personalisation, `global_colors` / `global_typography` overrides. `ai-import-template` for AI-rewritten copy (+ `browse-template-images` for image slots).
- **download-preset** — single marketplace preset; pro presets need the matching pro plugin active (`check-dependencies` first).

**Full kit import:** get the page list via `browse-presets` (or the public marketplace site — that's where name/topic search lives) → import each page and insert its content (check payload shape before saving) → finish every page before reporting done → set homepage + site settings → verify each page renders.

## CODE SNIPPETS

Local snippets = `nxt-code-snippet` posts OR Nexter Pro file storage; `list-local-snippets` returns both with the `post_id` / `file_id` used by `get-snippet-info`, `save-snippet`, `update-snippet-details`.

- `browse-snippets` / `download-snippet` — marketplace (pro needs login + licence). `get-snippet-kit` — snippets inside a bundle.
- `import-snippet(name, type, code, ...)` — import a snippet payload directly into the local site (no cloud login required; PHP snippets start deactivated for safety; duplicate names declined unless `overwrite: true`).
- `save-snippet` — `stype:"new"` first upload, `"existing"` + `snippet_id` to update (check `get-existing-snippet` first so the cloud library stays clean).
- `update-snippet-details` — local-only; follow with `save-snippet "existing"` to sync the cloud copy.
- `delete-snippet` — cloud copy only (`confirm: true`); the local post/file stays.
- `delete-snippet-everywhere(snippet_id|file_id|post_id|name, confirm: true)` — atomically deletes BOTH local storage (post/file) AND the cloud record in one call. Guarded with `confirm: true` and `dry_run: true`.

## THEME BUILDER (Nexter Extension)

`nexter/*-template-builder` abilities manage header / footer / singular / archive / 404 / hooks / section templates with display rules — site-wide or conditional template requests route here, not to page content. `delete-template-builder` is permanent — confirm an id from `list-templates-builder`, never a title guess.

## THEMES (Bricks vs. everything else)

Only the active theme's content displays. Make sure the right theme is active for the builder you're working in; confirm with the user before switching (it changes the whole site) and note the other theme's pages won't display until switched back.

## WORKSPACES

`create-workspace` / `update-workspace` / `get-workspace-data` / `manage-workspace-template|-widget|-snippet` (add / remove / copy / move) / `get-shared-with-me` / `delete-workspace`.

- Multiple workspaces → confirm the target before creating or moving anything.
- Cross-workspace copy = the `manage-workspace-*` copy operation, not a manual duplicate-then-push.
- `delete-workspace` removes only the container — contents are kept safe; deleting them too is separate per-item calls.

## ACCOUNT & LICENCES

- `get-login-status` — state, email, token expiry, credits.
- `login` (`remember_me: true` = 90-day session), `login-api-key`, `signup`, `forgot-password`, social flow (`get-social-login-url` → user authorises in popup → `social-login` with state). **Credentials and licence keys always need the user's confirmation in chat first.**
- `licence-overview` (`refresh: true` for live credits) — check before a licence-gated action so you can tell the user what their plan covers. `sync-licence` after an upgrade/renewal. `activate-licence` / `delete-licence` (`confirm`). `logout` clears session + cached licence.

## PLUGIN SETTINGS & LIFECYCLE

- Toggles (`toggle-features-manager`, `toggle-widget-builders`, `toggle-design-templates`, `update-settings`): **only keys you pass change** — read the matching `get-*` first, touch only what was asked. Widgets missing everywhere? Check `get-widget-builders` — the builder toggle may simply be off.
- White label: `set-white-label` (`plugin_name` required, fields merge) / `reset-white-label` (`confirm`, restores default branding).
- `list-rollback-versions` → `rollback` (`confirm`) — installs a previous stable version; the way back is rolling forward. Confirm with the user first.
- `remove-database` — `get` / `configure` / `execute` (`confirm` + `dry_run`). Treat `execute` as data deletion.
