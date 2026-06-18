# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this plugin does

EtchFacets is a WordPress plugin providing a faceted search/filtering engine for the EtchWP page builder. It supplies the AJAX backend and JS controller; all facet UI is built visually in Etch's editor using data attributes.

## Repository structure

Source files live in `src/` — this is what gets zipped and shipped as the plugin:

```
src/
├── etchfacets.php                  # Bootstrap, hooks, asset enqueuing, pre_get_posts filter
├── includes/
│   ├── class-query-builder.php     # Translates facet selections → WP_Query args
│   ├── class-ajax-handler.php      # AJAX endpoints (filter + map markers)
│   ├── class-count-calculator.php  # Per-choice count calculation via direct SQL
│   ├── class-facet-renderer.php    # Shortcodes + etchfacets_display() helper
│   └── plugin-update-checker/      # PUC library (vendored, don't edit)
├── assets/
│   ├── js/etchfacets.js            # Frontend controller (vanilla JS)
│   ├── js/etchfacets-map.js        # Google Maps facet module
│   ├── js/etchfacets-builder.js    # Etch editor Settings Bar control
│   └── css/etchfacets.css          # Minimal base styles
```

The repo root contains only docs/CI (`FACETS.md`, `PLUGIN_REFERENCE.md`, `DEPLOY.md`, `IMPLEMENTATION_PLAN.md`, `.github/`). These files are **excluded** from the release zip — only `src/` ships.

## Architecture

Two filtering paths run on every facet interaction:

1. **Page fetch** — JS fetches the full page URL with facet params (`_facetname=val&_src_facetname=taxonomy:category&_logic_facetname=or`). The `pre_get_posts` hook (`etchfacets_filter_query`) modifies matching WP_Queries. JS extracts `.etchfacets-template` from the response HTML and swaps it in.

2. **Count fetch** — A separate `admin-ajax.php` POST (`etchfacets_filter` action) handled by `EtchFacets_Ajax_Handler`. For each facet it builds a query *excluding that facet's own selections* (so counts reflect what toggling a choice would return), then hits the DB directly via `term_relationships` or `postmeta` SQL grouped queries. Returns `{ html, counts, total, max_pages, page }`.

### Key class responsibilities

- **`EtchFacets_Query_Builder`** — `build_query_args()` / `build_query_args_excluding()`. Between-facet logic is always AND; within-facet logic is OR by default, configurable to AND via `data-etchfacet-logic`.
- **`EtchFacets_Ajax_Handler`** — Registers `wp_ajax_etchfacets_filter` and `wp_ajax_etchfacets_map`. Verifies nonce, reads JSON POST body, orchestrates query + count calculation.
- **`EtchFacets_Count_Calculator`** — Direct SQL for performance; does not use `WP_Query` for the count step.
- **`EtchFacets_Facet_Renderer`** — Registers shortcodes: `[etchfacets_listing]`, `[etchfacets_facet]`, `[etchfacets_reset]`, `[etchfacets_results_count]`, `[etchfacets_map]`. Also exposes `etchfacets_display()` PHP helper.

### Facet source types

`taxonomy:{name}` | `meta:{key}` | `meta_range:{key}` | `search` | `author` | `date` | `post_type` | `geo:{lat_key},{lng_key}`

### JS public API

```js
window.EtchFacets.refresh()
window.EtchFacets.reset()
window.EtchFacets.getSelections()   // { facets, sources, logic }
window.EtchFacets.on('loaded', fn)
window.EtchFacets.on('error', fn)
```

## Releasing a new version

Version must be bumped in **two places** in `src/etchfacets.php`: the plugin header (`* Version:`) and the `ETCHFACETS_VERSION` constant. Both must match.

```bash
# 1. Bump version in src/etchfacets.php (header + constant)
git add -A
git commit -m "Release 0.x.x"
git tag v0.x.x
git push origin main --tags

# 2. Create a GitHub Release from the tag
gh release create v0.x.x --title "0.x.x" --notes "..."
```

The GitHub Actions workflow (`.github/workflows/release.yml`) triggers on `release: published`, rsyncs `src/` into a clean `etchfacets/` folder, zips it, and attaches `etchfacets.zip` to the release. WordPress sites using the plugin receive the update via the bundled plugin-update-checker (PUC) library pointing at `github.com/endresol/EtchFacets`.

> PUC compares the GitHub release tag version against the `Version:` header in the installed plugin file — if the header is not bumped, PUC will keep offering the same update forever.

## PHP requirements

- PHP 8.1+ (`declare(strict_types=1)` in all files)
- WordPress 5.9+
- No build step — PHP and JS are shipped as-is

## Important conventions

- The `pre_get_posts` filter guards against affecting unrelated queries (menus, widgets, singular page main query) by scoping to the listing's post type via the `_pt` URL param sent by the JS.
- Meta queries from facets are **grouped** (not flat-merged) with any existing `meta_query` to prevent OR leakage — particularly important for the map bounding-box filter.
- The `plugin-update-checker/` directory is a vendored library — do not modify it. See `DEPLOY.md` for upgrade instructions.
