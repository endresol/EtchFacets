# EtchFacets — Plugin Reference

A faceted search/filtering engine for WordPress + EtchWP. The plugin provides the AJAX backend and JS controller — the developer builds all facet UI visually in Etch's editor.

---

## How It Works

```
User interacts with facet UI (checkboxes, dropdowns, etc.)
        ↓
etchfacets.js collects selections from [data-etchfacet] elements
        ↓
Fetches the current page URL with facet params appended
(PHP filters the main WP_Query via pre_get_posts)
        ↓
Response HTML replaces .etchfacets-template content
        ↓
Separate AJAX call fetches updated counts per facet choice
        ↓
JS updates count badges, ghost/hidden states, and browser URL
```

There are two filtering paths:

1. **Page fetch** — The JS fetches the full page with facet params in the URL. The `pre_get_posts` hook (`etchfacets_filter_query`) modifies the main WP_Query using `tax_query`, `meta_query`, etc. The `.etchfacets-template` HTML is extracted from the response and swapped in.

2. **Count fetch** — A separate `admin-ajax.php` POST (`etchfacets_filter` action) calculates per-choice counts. For each facet, it builds a query *excluding* that facet's own selections (so counts reflect what would happen if you toggled a choice), then counts terms/meta values among the matching posts.

---

## File Structure

```
EtchFacets/
├── etchfacets.php                      # Bootstrap, hooks, asset enqueuing
├── includes/
│   ├── class-query-builder.php         # Translates facet selections → WP_Query args
│   ├── class-ajax-handler.php          # AJAX endpoint for filtering + counts
│   ├── class-count-calculator.php      # Per-choice count calculation
│   └── class-facet-renderer.php        # Shortcodes + PHP render helpers
├── assets/
│   ├── js/etchfacets.js                # Frontend controller (vanilla JS)
│   └── css/etchfacets.css              # Minimal base styles
└── IMPLEMENTATION_PLAN.md              # Full architecture plan
```

---

## Core Classes

### `EtchFacets_Query_Builder`
Translates facet selections into native `WP_Query` arguments.

- `build_query_args($facets, $sources, $logic, $base_args)` — Main method. Returns complete WP_Query args.
- `build_query_args_excluding($exclude_facet, ...)` — Same but omits one facet (used by count calculator).
- `parse_source($source)` — Splits `"taxonomy:category"` into `['type' => 'taxonomy', 'value' => 'category']`.

**Facet logic:**
- Between facets: **AND** (selecting a category AND a tag narrows results)
- Within a facet: **OR** by default (selecting "Red" OR "Blue" expands), configurable to **AND**

### `EtchFacets_Ajax_Handler`
Registers `wp_ajax_etchfacets_filter` / `wp_ajax_nopriv_etchfacets_filter`.

- Verifies nonce
- Reads JSON-encoded POST data (facets, sources, logic, query_context)
- Builds and runs WP_Query via QueryBuilder
- Captures loop HTML with `ob_start()`
- Calculates counts via CountCalculator
- Returns JSON: `{ html, counts, total, max_pages, page }`

### `EtchFacets_Count_Calculator`
For each facet, calculates how many results each choice would return.

**Algorithm:**
1. Build a WP_Query using all *other* facets' selections (exclude current facet)
2. Get matching post IDs
3. For taxonomy: direct SQL on `term_relationships` grouped by term
4. For meta: direct SQL on `postmeta` grouped by `meta_value`
5. For meta_range: SQL MIN/MAX on numeric meta values

### `EtchFacets_Facet_Renderer`
Provides shortcodes and a PHP helper for rendering facet UI.

**Shortcodes:**
- `[etchfacets_listing]` — Outputs a `.etchfacets-template` container with initial server-rendered loop
- `[etchfacets_facet]` — Outputs a facet with choices (checkboxes, dropdown, radio, search, range)
- `[etchfacets_reset]` — Reset button
- `[etchfacets_results_count]` — Live total count display

**PHP helper:**
- `etchfacets_display($name, $source, $options)` — Render a facet in theme templates

---

## Supported Facet Source Types

| Source | WP_Query Arg | Example |
|---|---|---|
| `taxonomy:{name}` | `tax_query` | `taxonomy:category`, `taxonomy:product_cat` |
| `meta:{key}` | `meta_query` | `meta:color`, `meta:_brand` |
| `meta_range:{key}` | `meta_query` BETWEEN | `meta_range:price` |
| `search` | `s` | Full-text search |
| `author` | `author__in` | Author filter |
| `date` | `date_query` | Date range (after/before) |
| `post_type` | `post_type` | Post type filter |
| `geo:{lat},{lng}` | `meta_query` BETWEEN (bbox) | `geo:_ef_lat,_ef_lng` — map viewport |
| `sort` | `orderby` / `order` | `sort` — value is a combined key, e.g. `date_desc`; no filtering, excluded from counts |

---

## Supported UI Types

| Type | Input Elements | Trigger |
|---|---|---|
| **Checkboxes** | `input[type="checkbox"]` | `change` (immediate) |
| **Radio** | `input[type="radio"]` | `change` (immediate) |
| **Dropdown** | `<select>` | `change` (immediate) |
| **Search** | `input[type="search"]` or `input[type="text"]` | `input` (300ms debounce) |
| **Range** | `.etchfacet-min` + `.etchfacet-max` (number or range inputs) | `input` (500ms debounce) |
| **Hierarchical** | Checkboxes inside `.etchfacets-tree-item` with `.etchfacets-tree-children` | `change` + tree toggles |

---

## Data Attribute API

The primary integration method. The developer builds markup in Etch and adds these attributes:

### Facet container
```html
<div data-etchfacet="category"
     data-etchfacet-source="taxonomy:category"
     data-etchfacet-logic="or">
    <!-- inputs go here -->
</div>
```

| Attribute | Required | Description |
|---|---|---|
| `data-etchfacet` | Yes | Unique facet name |
| `data-etchfacet-source` | Yes | Data source (e.g., `taxonomy:category`, `meta:color`) |
| `data-etchfacet-logic` | No | `or` (default) or `and` — logic within this facet |

### Listing container
```html
<div class="etchfacets-template"
     data-etchfacets-query='{"post_type":"post","posts_per_page":12}'>
    <!-- loop output goes here -->
</div>
```

Or with individual attributes (Etch-friendly):
```html
<div class="etchfacets-template"
     data-etchfacets-post-type="post"
     data-etchfacets-posts-per-page="12"
     data-etchfacets-orderby="date"
     data-etchfacets-order="DESC">
```

### Count badges
```html
<span class="etchfacet-count"></span>
```
JS auto-updates these with `(N)` after each filter.

### Reset button
```html
<button class="etchfacets-reset" type="button">Reset</button>
```

### Results count
```html
<span data-etchfacets-total></span>
```

### Active filters summary
```html
<div data-etchfacets-active-filters></div>
```
JS keeps this populated with a removable chip per active facet value. See
[FACETS.md](FACETS.md#active-filters-summary).

---

## CSS Classes

| Class | Applied by | Purpose |
|---|---|---|
| `.etchfacets-template` | Developer | Marks the listing container |
| `.etchfacets-loading` | JS (during fetch) | Loading state — default: opacity 0.4, no pointer events |
| `.etchfacet-count` | Developer | Count badge next to choices |
| `.etchfacet-ghost` | JS | Choice has 0 results — default: opacity 0.35, no pointer events |
| `.etchfacet-hidden` | JS | Choice has 0 results and unchecked — default: `display: none` |
| `.etchfacets-reset` | Developer | Reset button |
| `.etchfacets-no-results` | JS/PHP | Shown when query returns 0 posts |
| `.etchfacets-hierarchy` | Developer | Hierarchical tree facet container |
| `.etchfacets-tree-item` | Developer | Single item in hierarchy |
| `.etchfacets-tree-toggle` | Developer | Expand/collapse button (+/−) |
| `.etchfacets-tree-children` | Developer | Child items container |
| `.etchfacets-pagination-prev` / `-next` | Developer | Prev/Next pager buttons — auto-disabled at first/last page |
| `.etchfacets-pagination-status` | Developer | Auto-populated with `Page X of Y` |
| `.etchfacets-load-more` | Developer | Appends the next page instead of replacing the listing; auto-disabled (with `.etchfacets-load-more--exhausted`) once exhausted |
| `.etchfacets-map` | Developer | Map facet container |
| `.etchfacets-map--loading` | JS (during marker fetch) | Loading state |
| `.etchfacets-map--error` | JS | Shown when the Google Maps API fails to load (e.g. missing key) |
| `.etchfacets-map-info` | JS | Info-window content wrapper |
| `.etchfacets-map-info-badge` | JS | Category badge inside the info window (colored via `data-etchfacet-color-taxonomy`) |
| `.etchfacets-map-search-area` | JS | Floating "Search this area" button; gets `--visible` after the user pans/zooms |
| `.etchfacets-range-reset` | Developer | Icon/button inside a range/slider facet; snaps that facet's inputs back to their full min/max and re-fetches |
| `.etchfacets-active-filter` | JS | One removable chip inside `[data-etchfacets-active-filters]` |
| `.etchfacets-active-filter-remove` | Developer | The chip's `×` button |

---

## URL State

Facet selections are reflected in the URL for bookmarkability and back/forward support.

**Browser URL** (clean — user-facing):
```
/shop/?_category=design,photography&_price=100,500
```

**Internal fetch URL** (includes source metadata for PHP):
```
/shop/?_category=design,photography&_src_category=taxonomy:category&_logic_category=or
```

The JS reads URL params on page load (`readUrlState`) to restore facet selections, and updates the URL via `history.pushState` after each filter. `popstate` is handled for back/forward navigation.

---

## JS Public API

```js
window.EtchFacets.refresh()          // Re-fetch with current selections
window.EtchFacets.reset()            // Clear all inputs and re-fetch
window.EtchFacets.getSelections()    // Returns { facets, sources, logic }
window.EtchFacets.on('loaded', fn)   // Listen to etchfacets:loaded event
window.EtchFacets.on('error', fn)    // Listen to etchfacets:error event
```

---

## Etch Integration Points

| Hook | Usage |
|---|---|
| `etch/canvas/enqueue_assets` | Loads `etchfacets.js` + config in Etch canvas |
| `etch_autocompletion_classes` | Registers `.etchfacets-*` classes for editor autocomplete |
| `wp_enqueue_scripts` | Loads assets on frontend |

---

## WordPress Hooks Provided

| Hook | Type | Description |
|---|---|---|
| `etchfacets_ajax_response` | Filter | Modify the AJAX JSON response before sending |

---

## Server-Side Filtering (pre_get_posts)

The plugin also filters the main WP_Query on initial page load when facet params are in the URL. This ensures:
- The initial HTML matches the facet state (progressive enhancement)
- The page works without JS (degraded but functional)

`etchfacets_filter_query()` only applies to queries whose post type matches the faceted taxonomies, preventing menus/widgets from being affected.
